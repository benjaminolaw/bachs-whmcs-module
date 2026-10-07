<?php

namespace HarmonWeb\Bachs;

/**
 * Applies verified Bachs payments to WHMCS invoices.
 *
 * Security model: nothing from a webhook body, redirect or query string is
 * trusted. Every path ends here, and this class always re-fetches the
 * checkout/payment from the Bachs API, checks amount + currency against what
 * we requested, and claims the payment ID atomically before touching WHMCS.
 * The webhook, the return page and the cron can therefore race safely.
 */
class PaymentProcessor
{
    const SUCCESS_STATUSES = ['COMPLETED', 'SUCCEEDED', 'SUCCESS', 'SUCCESSFUL', 'PAID'];
    const FAILED_STATUSES = ['FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED', 'DECLINED'];

    /** Renewal payment within this fraction of the agreed amount counts as full payment. */
    const RENEWAL_TOLERANCE = 0.02;

    /** @var Config */
    private $config;

    /** @var ApiClient */
    private $api;

    public function __construct(Config $config, ApiClient $api)
    {
        $this->config = $config;
        $this->api = $api;
    }

    public static function isSuccess($status)
    {
        return in_array(strtoupper((string) $status), self::SUCCESS_STATUSES, true);
    }

    // ============================================================ checkouts

    /**
     * Re-check a checkout we created and apply it to its invoice if paid.
     *
     * @param object $row mod_bachs_checkouts row
     * @return string outcome: applied|already_applied|pending|expired|cancelled|failed|mismatch|credited
     * @throws ApiException on API failures (caller decides whether to retry)
     */
    public function processCheckout($row, $source)
    {
        if (!$row->checkout_id) {
            return 'pending';
        }
        if ((bool) $row->sandbox !== $this->config->isSandbox()) {
            // Created in the other environment; this API key cannot see it.
            return 'pending';
        }

        $session = $this->api->getCheckoutSession($row->checkout_id);
        $status = strtoupper((string) ($session['status'] ?? ''));
        $paymentStatus = strtolower((string) ($session['payment_status'] ?? ''));
        $charge = isset($session['charge']) && is_array($session['charge']) ? $session['charge'] : [];

        Store::updateCheckout($row->id, [
            'last_checked_at' => Store::now(),
            'check_count' => (int) $row->check_count + 1,
        ]);

        $paid = $status === 'COMPLETED' || $paymentStatus === 'succeeded'
            || ($charge && self::isSuccess($charge['status'] ?? ''));

        if (!$paid) {
            if ($status === 'EXPIRED' || $status === 'CANCELLED') {
                Store::updateCheckout($row->id, ['status' => strtolower($status)]);
                return strtolower($status);
            }
            if ($paymentStatus === 'failed' && $row->status !== 'failed') {
                Store::updateCheckout($row->id, ['status' => 'failed']);
                Logger::transaction(['source' => $source, 'invoice_id' => $row->invoice_id, 'checkout_id' => $row->checkout_id, 'status' => $status, 'payment_status' => $paymentStatus], 'Failure');
                return 'failed';
            }
            return 'pending';
        }

        // ---- integrity checks: the session must be the one we created, for the amount we asked.
        $problems = [];
        if (!empty($session['reference']) && $session['reference'] !== $row->reference) {
            $problems[] = 'reference mismatch';
        }
        $metaInvoice = $session['metadata']['whmcs_invoice_id'] ?? null;
        if ($metaInvoice !== null && (int) $metaInvoice !== (int) $row->invoice_id) {
            $problems[] = 'invoice metadata mismatch';
        }
        if (Money::normalizeCurrency($session['currency'] ?? '') !== Money::normalizeCurrency($row->gateway_currency)) {
            $problems[] = 'currency mismatch';
        }
        if (!Money::equals($session['amount'] ?? '0', $row->gateway_amount)) {
            $problems[] = 'amount mismatch';
        }
        if ($charge) {
            $chargeCurrency = Money::normalizeCurrency($charge['currency'] ?? $row->gateway_currency);
            if (isset($charge['amount_paid']) && $charge['amount_paid'] !== null && $chargeCurrency === Money::normalizeCurrency($row->gateway_currency)
                && Money::compare($charge['amount_paid'], $row->gateway_amount) < 0) {
                $problems[] = 'underpaid (' . $charge['amount_paid'] . ' of ' . $row->gateway_amount . ')';
            }
        }
        if ($problems) {
            // Terminal until an admin reviews it; excluded from automatic retries.
            Store::updateCheckout($row->id, ['status' => 'mismatch']);
            Logger::transaction([
                'source' => $source,
                'invoice_id' => $row->invoice_id,
                'checkout_id' => $row->checkout_id,
                'problems' => $problems,
                'expected' => $row->gateway_amount . ' ' . $row->gateway_currency,
                'received' => ($session['amount'] ?? '?') . ' ' . ($session['currency'] ?? '?'),
            ], 'Verification Failed');
            Logger::activity('Payment for invoice #' . $row->invoice_id . ' needs review: ' . implode(', ', $problems), $row->client_id);
            return 'mismatch';
        }

        $paymentId = (string) ($charge['payment_id'] ?? '');
        if ($paymentId === '') {
            $paymentId = (string) $row->checkout_id; // still unique; used as the WHMCS transaction id
        }
        $subscriptionId = $charge['subscription_id'] ?? ($charge['invoice']['subscription_id'] ?? null);

        $outcome = $this->applyToInvoice(
            $paymentId,
            'checkout',
            (int) $row->invoice_id,
            (int) $row->client_id,
            (float) $row->invoice_amount,
            $row->invoice_currency,
            $row->gateway_amount,
            $row->gateway_currency,
            self::feeFor($charge, $row->invoice_currency),
            null,
            $source,
            ['checkout_id' => $row->checkout_id, 'payment_method' => $charge['payment_method'] ?? ($session['payment_method'] ?? null)]
        );

        if (in_array($outcome, ['applied', 'already_applied', 'credited'], true)) {
            Store::updateCheckout($row->id, [
                'status' => 'completed',
                'payment_id' => $paymentId,
                'subscription_id' => $subscriptionId,
            ]);
            if ($row->mode === CheckoutService::MODE_SUBSCRIPTION) {
                $fresh = Store::checkoutByCheckoutId($row->checkout_id);
                (new SubscriptionService($this->config, $this->api))->linkFromCheckout($fresh, $subscriptionId, $session);
            }
        }
        return $outcome;
    }

    // ============================================================= renewals

    /**
     * Handle a Bachs payment by ID: either the first charge of a checkout we
     * created, or an automatic subscription renewal.
     *
     * @return string outcome
     */
    public function processPaymentId($paymentId, $source)
    {
        $existing = Store::paymentById($paymentId);
        if ($existing && in_array($existing->status, ['applied', 'credited', 'duplicate'], true)) {
            return 'already_applied';
        }

        $payment = $this->api->getPayment($paymentId);

        if (!empty($payment['checkout_id'])) {
            $row = Store::checkoutByCheckoutId($payment['checkout_id']);
            if ($row) {
                return $this->processCheckout($row, $source);
            }
        }
        if (!empty($payment['reference'])) {
            $row = Store::checkoutByReference($payment['reference']);
            if ($row) {
                return $this->processCheckout($row, $source);
            }
        }

        $subscriptionId = $payment['subscription_id'] ?? ($payment['invoice']['subscription_id'] ?? null);
        if ($subscriptionId) {
            return $this->processRenewal($payment, $subscriptionId, $source);
        }
        return 'not_ours';
    }

    /**
     * Apply an automatic subscription charge to the matching WHMCS renewal invoice.
     */
    public function processRenewal(array $payment, $subscriptionId, $source)
    {
        $paymentId = (string) ($payment['payment_id'] ?? ($payment['id'] ?? ''));
        if ($paymentId === '' || !self::isSuccess($payment['status'] ?? '')) {
            return 'pending';
        }
        $sub = Store::subscription($subscriptionId);
        if (!$sub) {
            Logger::transaction(['source' => $source, 'payment_id' => $paymentId, 'subscription_id' => $subscriptionId, 'note' => 'Unknown subscription'], 'Unmatched');
            return 'not_ours';
        }

        $invoiceIds = Whmcs::unpaidInvoicesForItems(json_decode($sub->items, true) ?: [], $sub->client_id);
        if (!$invoiceIds) {
            // Bachs charged before WHMCS generated the renewal invoice (or it was paid
            // another way). Park it; the cron re-matches and eventually credits it.
            $existing = Store::paymentById($paymentId);
            if (!$existing) {
                try {
                    \WHMCS\Database\Capsule::table(Schema::T_PAYMENTS)->insert([
                        'payment_id' => $paymentId,
                        'kind' => 'renewal',
                        'client_id' => $sub->client_id,
                        'subscription_id' => $subscriptionId,
                        'amount' => (string) $payment['amount'],
                        'currency' => Money::normalizeCurrency($payment['currency'] ?? $sub->currency),
                        'status' => 'unmatched',
                        'note' => 'No unpaid invoice found for subscribed items',
                        'created_at' => Store::now(),
                        'updated_at' => Store::now(),
                    ]);
                } catch (\Exception $e) {
                    // Inserted concurrently; fine.
                }
                Logger::transaction(['source' => $source, 'payment_id' => $paymentId, 'subscription_id' => $subscriptionId, 'note' => 'Awaiting renewal invoice'], 'Unmatched');
            }
            return 'unmatched';
        }

        $invoice = Whmcs::invoice($invoiceIds[0]);
        $paidAmount = (string) ($payment['amount_paid'] ?? $payment['amount']);
        $paidCurrency = Money::normalizeCurrency($payment['currency'] ?? $sub->currency);
        $applyAmount = $this->renewalApplyAmount($paidAmount, $paidCurrency, $sub, $invoice);
        if ($applyAmount === null) {
            Logger::transaction(['source' => $source, 'payment_id' => $paymentId, 'note' => 'Cannot convert ' . $paidCurrency . ' to ' . $invoice->currency_code], 'Unmatched');
            return 'unmatched';
        }

        return $this->applyToInvoice(
            $paymentId,
            'renewal',
            (int) $invoice->id,
            (int) $sub->client_id,
            $applyAmount,
            $invoice->currency_code,
            $paidAmount,
            $paidCurrency,
            self::feeFor($payment, $invoice->currency_code),
            $subscriptionId,
            $source,
            ['billing_reason' => $payment['billing_reason'] ?? null]
        );
    }

    /**
     * Amount (in invoice currency) to credit to a renewal invoice.
     *
     * Same currency: exactly what was paid. Different currency (NGN invoice,
     * USD subscription): a charge of the full agreed subscription amount settles
     * the invoice balance in full, otherwise convert at WHMCS rates.
     */
    private function renewalApplyAmount($paidAmount, $paidCurrency, $sub, $invoice)
    {
        $invoiceCurrency = Money::normalizeCurrency($invoice->currency_code);
        if ($paidCurrency === $invoiceCurrency) {
            return round((float) $paidAmount, 2);
        }
        if (Money::normalizeCurrency($sub->currency) === $paidCurrency
            && (float) $paidAmount >= (float) $sub->amount * (1 - self::RENEWAL_TOLERANCE)) {
            return round((float) $invoice->balance, 2);
        }
        return Whmcs::convert($paidAmount, $paidCurrency, $invoiceCurrency);
    }

    /**
     * Retry a parked renewal payment; credit it to the client after the
     * configured number of days so customer money never goes missing.
     */
    public function retryUnmatched($row)
    {
        $sub = $row->subscription_id ? Store::subscription($row->subscription_id) : null;
        if ($sub) {
            $invoiceIds = Whmcs::unpaidInvoicesForItems(json_decode($sub->items, true) ?: [], $sub->client_id);
            if ($invoiceIds) {
                return $this->processRenewal(
                    ['payment_id' => $row->payment_id, 'status' => 'COMPLETED', 'amount' => $row->amount, 'currency' => $row->currency],
                    $row->subscription_id,
                    'cron'
                );
            }
        }

        $days = $this->config->unmatchedCreditDays();
        if ($days > 0 && $row->client_id && strtotime($row->created_at) < time() - $days * 86400) {
            $clientCurrency = Whmcs::clientCurrency($row->client_id);
            $amount = Whmcs::convert($row->amount, $row->currency, $clientCurrency->code);
            if ($amount === null) {
                return 'unmatched';
            }
            // Conditional update is the claim: only one process can move it out of "unmatched".
            $affected = \WHMCS\Database\Capsule::table(Schema::T_PAYMENTS)
                ->where('payment_id', $row->payment_id)
                ->where('status', 'unmatched')
                ->update(['status' => 'processing', 'updated_at' => Store::now()]);
            if ($affected !== 1) {
                return 'already_applied';
            }
            try {
                Whmcs::addCredit($row->client_id, $amount, 'Bachs auto-pay payment ' . $row->payment_id . ' (no matching invoice)');
            } catch (\Exception $e) {
                Store::updatePayment($row->payment_id, ['status' => 'unmatched', 'note' => mb_substr($e->getMessage(), 0, 1000)]);
                throw $e;
            }
            Store::updatePayment($row->payment_id, ['status' => 'credited', 'applied_amount' => $amount, 'note' => 'Added as account credit']);
            Logger::transaction(['payment_id' => $row->payment_id, 'client_id' => $row->client_id, 'credit' => $amount], 'Credited');
            Logger::activity('Bachs renewal payment ' . $row->payment_id . ' had no matching invoice and was added as credit', $row->client_id);
            return 'credited';
        }
        return 'unmatched';
    }

    // ============================================================ internals

    /**
     * Claim the payment and record it on the invoice.
     */
    private function applyToInvoice(
        $paymentId,
        $kind,
        $invoiceId,
        $clientId,
        $invoiceAmount,
        $invoiceCurrency,
        $gatewayAmount,
        $gatewayCurrency,
        $fee,
        $subscriptionId,
        $source,
        array $extra
    ) {
        $claimed = Store::claimPayment($paymentId, [
            'kind' => $kind,
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            'subscription_id' => $subscriptionId,
            'amount' => (string) $gatewayAmount,
            'currency' => $gatewayCurrency,
        ]);
        if (!$claimed) {
            return 'already_applied';
        }

        try {
            if (Whmcs::transactionExists($paymentId)) {
                Store::updatePayment($paymentId, ['status' => 'applied', 'invoice_id' => $invoiceId, 'note' => 'Transaction already recorded']);
                return 'already_applied';
            }

            $invoice = Whmcs::invoice($invoiceId);
            if (!$invoice) {
                throw new \RuntimeException('Invoice #' . $invoiceId . ' no longer exists');
            }

            $log = $extra + [
                'source' => $source,
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'subscription_id' => $subscriptionId,
                'paid' => $gatewayAmount . ' ' . $gatewayCurrency,
                'applied' => $invoiceAmount . ' ' . $invoiceCurrency,
                'fee' => $fee,
                'sandbox' => $this->config->isSandbox(),
            ];

            if ($invoice->status !== 'Unpaid') {
                // Paid twice (e.g. customer also paid another way) or invoice cancelled.
                // Keep the customer's money as account credit and flag for review.
                $credit = Money::normalizeCurrency($invoiceCurrency) === Money::normalizeCurrency($invoice->currency_code)
                    ? $invoiceAmount
                    : Whmcs::convert($invoiceAmount, $invoiceCurrency, $invoice->currency_code);
                Whmcs::addCredit($clientId, $credit, 'Bachs payment ' . $paymentId . ' for invoice #' . $invoiceId . ' (invoice was already ' . $invoice->status . ')');
                Store::updatePayment($paymentId, ['status' => 'credited', 'applied_amount' => $credit, 'note' => 'Invoice status ' . $invoice->status]);
                Logger::transaction($log + ['note' => 'Invoice already ' . $invoice->status . '; added as credit'], 'Credited');
                Logger::activity('Bachs payment ' . $paymentId . ' arrived for invoice #' . $invoiceId . ' which was already ' . $invoice->status . '; added as credit', $clientId);
                return 'credited';
            }

            Whmcs::addInvoicePayment($invoiceId, $paymentId, $invoiceAmount, $fee);
            Store::updatePayment($paymentId, ['status' => 'applied', 'applied_amount' => $invoiceAmount, 'invoice_id' => $invoiceId]);
            Logger::transaction($log, 'Success');
            return 'applied';
        } catch (\Exception $e) {
            Store::updatePayment($paymentId, ['status' => 'error', 'note' => mb_substr($e->getMessage(), 0, 1000)]);
            Logger::transaction(['source' => $source, 'invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'error' => $e->getMessage()], 'Error');
            throw $e;
        }
    }

    /** Bachs reports fees in USD only; record them when the invoice is in USD. */
    private static function feeFor(array $payment, $invoiceCurrency)
    {
        if (Money::normalizeCurrency($invoiceCurrency) === 'USD' && isset($payment['fee_usd']) && is_numeric($payment['fee_usd'])) {
            return round((float) $payment['fee_usd'], 2);
        }
        return 0.0;
    }
}
