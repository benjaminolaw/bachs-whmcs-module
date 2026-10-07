<?php

namespace HarmonWeb\Bachs;

/**
 * Creates Bachs hosted checkout sessions for WHMCS invoices.
 *
 *  - one_time:     ad-hoc `pricing` in the invoice (or converted) currency;
 *                  customer can pay by card, crypto, bank transfer or mobile money.
 *  - subscription: a recurring catalog product (USD) in `product_cart`; the
 *                  card is saved and Bachs charges it automatically every cycle.
 */
class CheckoutService
{
    const MODE_ONE_TIME = 'one_time';
    const MODE_SUBSCRIPTION = 'subscription';
    const SUBSCRIPTION_CURRENCY = 'USD';

    /** Fallback when /v1/currencies/supported is unreachable. */
    const DEFAULT_FIAT = ['USD', 'NGN', 'GHS', 'KES', 'UGX', 'TZS', 'RWF', 'XAF', 'XOF', 'ZMW'];

    /** @var Config */
    private $config;

    /** @var ApiClient */
    private $api;

    public function __construct(Config $config, ApiClient $api)
    {
        $this->config = $config;
        $this->api = $api;
    }

    // ------------------------------------------------------------- form token

    public static function formToken(Config $config, $invoiceId, $mode)
    {
        return hash_hmac('sha256', 'pay|' . (int) $invoiceId . '|' . $mode, $config->signingKey());
    }

    public static function verifyFormToken(Config $config, $invoiceId, $mode, $token)
    {
        return is_string($token) && hash_equals(self::formToken($config, $invoiceId, $mode), $token);
    }

    // ----------------------------------------------------------------- quotes

    /**
     * Amount and currency that would be charged for a one-time payment.
     * Honours the WHMCS "Convert To For Processing" gateway setting.
     *
     * @return array ['amount' => '10.00', 'currency' => 'USD', 'invoice_amount' => 10.0, 'invoice_currency' => 'USD']
     */
    public function quoteOneTime($invoice)
    {
        $invoiceCurrency = Money::normalizeCurrency($invoice->currency_code);
        $invoiceAmount = round((float) $invoice->balance, 2);
        $currency = $invoiceCurrency;
        $amount = $invoiceAmount;

        $convertTo = $this->config->convertTo();
        if ($convertTo) {
            $target = \WHMCS\Database\Capsule::table('tblcurrencies')->where('id', (int) $convertTo)->first();
            if ($target && Money::normalizeCurrency($target->code) !== $invoiceCurrency) {
                $converted = Whmcs::convert($invoiceAmount, $invoiceCurrency, $target->code);
                if ($converted !== null) {
                    $currency = Money::normalizeCurrency($target->code);
                    $amount = $converted;
                }
            }
        }

        return [
            'amount' => Money::format($amount, $currency),
            'currency' => $currency,
            'invoice_amount' => $invoiceAmount,
            'invoice_currency' => $invoiceCurrency,
        ];
    }

    /**
     * Auto-pay plan for an invoice, or ['eligible' => false, 'reason' => ...].
     */
    public function quoteSubscription($invoice)
    {
        if (!$this->config->autopayEnabled()) {
            return ['eligible' => false, 'reason' => 'Auto-pay is disabled'];
        }
        $analysis = InvoiceAnalyzer::analyze($invoice);
        if (!$analysis['eligible']) {
            return $analysis;
        }

        $invoiceCurrency = Money::normalizeCurrency($invoice->currency_code);
        $invoiceAmount = round((float) $invoice->balance, 2);
        if ($invoiceCurrency === self::SUBSCRIPTION_CURRENCY) {
            $amount = $invoiceAmount;
        } elseif ($this->config->autopayConvertNonUsd()) {
            $amount = Whmcs::convert($invoiceAmount, $invoiceCurrency, self::SUBSCRIPTION_CURRENCY);
            if ($amount === null) {
                return ['eligible' => false, 'reason' => 'USD is not configured as a currency in WHMCS'];
            }
        } else {
            return ['eligible' => false, 'reason' => 'Bachs subscriptions are billed in USD only'];
        }
        if ($amount < 1) {
            return ['eligible' => false, 'reason' => 'Amount below the Bachs minimum'];
        }

        return $analysis + [
            'amount' => Money::format($amount, self::SUBSCRIPTION_CURRENCY),
            'currency' => self::SUBSCRIPTION_CURRENCY,
            'invoice_amount' => $invoiceAmount,
            'invoice_currency' => $invoiceCurrency,
        ];
    }

    public function supportedFiat()
    {
        $cacheKey = 'supported_fiat_' . ($this->config->isSandbox() ? 'sandbox' : 'live');
        $cached = Store::kvGet($cacheKey, 86400);
        if ($cached) {
            $list = json_decode($cached, true);
            if (is_array($list) && $list) {
                return $list;
            }
        }
        try {
            $resp = $this->api->supportedCurrencies();
            $list = isset($resp['fiat']) && is_array($resp['fiat']) ? array_map([Money::class, 'normalizeCurrency'], $resp['fiat']) : [];
            if ($list) {
                Store::kvSet($cacheKey, json_encode($list));
                return $list;
            }
        } catch (\Exception $e) {
            Logger::debug('supportedCurrencies', $e->getMessage());
        }
        return self::DEFAULT_FIAT;
    }

    // --------------------------------------------------------------- creation

    /**
     * Create (or reuse) a checkout session and return its hosted URL.
     *
     * @throws \RuntimeException with a customer-safe message
     */
    public function start($invoiceId, $mode)
    {
        $invoice = Whmcs::invoice($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }
        if ($invoice->status !== 'Unpaid') {
            throw new \RuntimeException('This invoice is not awaiting payment.');
        }
        if ($invoice->balance <= 0) {
            throw new \RuntimeException('This invoice has no balance due.');
        }

        if ($mode === self::MODE_SUBSCRIPTION) {
            $quote = $this->quoteSubscription($invoice);
            if (empty($quote['eligible'])) {
                throw new \RuntimeException('Automatic payments are not available for this invoice.');
            }
            $methods = ['card'];
        } else {
            $mode = self::MODE_ONE_TIME;
            $quote = $this->quoteOneTime($invoice);
            if (!in_array($quote['currency'], $this->supportedFiat(), true)) {
                throw new \RuntimeException('Payments in ' . $quote['currency'] . ' are not supported by this payment method.');
            }
            $methods = $this->config->allowedPaymentMethods();
        }

        $sandbox = $this->config->isSandbox();
        $existing = Store::reusableCheckout($invoice->id, $mode, $quote['amount'], $quote['currency'], $sandbox);
        if ($existing) {
            return $existing->checkout_url;
        }

        $reference = 'WHMCS-' . $invoice->id . '-' . bin2hex(random_bytes(6));
        $plan = null;
        $customer = $this->customerPayload($invoice->userid, $mode === self::MODE_SUBSCRIPTION);

        $body = [
            'customer' => $customer,
            'reference' => $reference,
            'success_url' => $this->config->systemUrl() . 'modules/gateways/bachs/return.php',
            'cancel_url' => $this->config->systemUrl() . 'viewinvoice.php?id=' . (int) $invoice->id,
            'expires_in_minutes' => $this->config->checkoutExpiryMinutes(),
            'metadata' => [
                'source' => 'whmcs',
                'whmcs_invoice_id' => (string) $invoice->id,
                'whmcs_client_id' => (string) $invoice->userid,
                'mode' => $mode,
            ],
        ];
        if ($methods) {
            $body['allowed_payment_method_types'] = $methods;
        }

        if ($mode === self::MODE_SUBSCRIPTION) {
            $productId = $this->ensureProduct($quote['amount'], $quote['cadence']);
            $body['product_cart'] = [['product_id' => $productId, 'quantity' => 1]];
            $body['billing_currency'] = self::SUBSCRIPTION_CURRENCY;
            $plan = [
                'product_id' => $productId,
                'items' => $quote['items'],
                'interval' => $quote['cadence']['interval'],
                'frequency' => (int) $quote['cadence']['frequency'],
                'amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'invoice_amount' => $quote['invoice_amount'],
                'invoice_currency' => $quote['invoice_currency'],
                'tax_ratio' => $quote['tax_ratio'],
            ];
        } else {
            $body['pricing'] = [
                'currency' => $quote['currency'],
                'amount' => $quote['amount'],
                'price_type' => 'fixed',
            ];
        }

        $rowId = Store::insertCheckout([
            'reference' => $reference,
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->userid,
            'mode' => $mode,
            'invoice_amount' => $quote['invoice_amount'],
            'invoice_currency' => $quote['invoice_currency'],
            'gateway_amount' => $quote['amount'],
            'gateway_currency' => $quote['currency'],
            'subscription_plan' => $plan ? json_encode($plan) : null,
            'sandbox' => $sandbox ? 1 : 0,
            'status' => 'open',
        ]);

        try {
            $resp = $this->api->createCheckoutSession($body, 'checkout-' . $reference);
        } catch (ApiException $e) {
            Store::updateCheckout($rowId, ['status' => 'error']);
            Logger::transaction([
                'action' => 'create_checkout',
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'http_status' => $e->getHttpStatus(),
            ], 'Checkout Error');
            throw new \RuntimeException('We could not start the payment right now. Please try again shortly.');
        }

        if (empty($resp['checkout_id']) || empty($resp['checkout_url']) || !self::isSafeUrl($resp['checkout_url'])) {
            Store::updateCheckout($rowId, ['status' => 'error']);
            Logger::transaction(['action' => 'create_checkout', 'invoice_id' => $invoice->id, 'response' => $resp], 'Checkout Error');
            throw new \RuntimeException('The payment provider returned an unexpected response.');
        }

        $expires = !empty($resp['expires_at']) ? strtotime($resp['expires_at']) : false;
        Store::updateCheckout($rowId, [
            'checkout_id' => $resp['checkout_id'],
            'checkout_url' => $resp['checkout_url'],
            'expires_at' => date('Y-m-d H:i:s', $expires ?: time() + 60 * $this->config->checkoutExpiryMinutes()),
        ]);

        return $resp['checkout_url'];
    }

    /**
     * Only redirect customers to well-formed https URLs. The URL comes from an
     * authenticated TLS response, so the host itself is not pinned (Bachs may
     * serve hosted pages from more than one domain); unexpected hosts are logged.
     */
    public static function isSafeUrl($url)
    {
        $parts = parse_url((string) $url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        if ($host !== 'bachs.io' && substr($host, -strlen('.bachs.io')) !== '.bachs.io') {
            Logger::activity('Redirecting to non-bachs.io hosted page host: ' . $host);
        }
        return true;
    }

    // -------------------------------------------------------------- customers

    /**
     * Existing Bachs customer reference when available (required for
     * subscriptions so the saved card and portal belong to the same customer),
     * otherwise inline customer details.
     */
    private function customerPayload($clientId, $required)
    {
        try {
            return ['customer_id' => $this->ensureCustomer($clientId)];
        } catch (\Exception $e) {
            if ($required) {
                throw new \RuntimeException('We could not prepare automatic payments for your account. Please try again shortly.');
            }
            $client = Whmcs::client($clientId);
            return [
                'email' => $client->email,
                'name' => trim($client->firstname . ' ' . $client->lastname) ?: $client->email,
            ];
        }
    }

    public function ensureCustomer($clientId)
    {
        $sandbox = $this->config->isSandbox();
        $existing = Store::customerId($clientId, $sandbox);
        if ($existing) {
            return $existing;
        }
        $client = Whmcs::client($clientId);
        if (!$client) {
            throw new \RuntimeException('Client not found');
        }
        $body = [
            'email' => $client->email,
            'name' => trim($client->firstname . ' ' . $client->lastname) ?: $client->email,
            'metadata' => ['whmcs_client_id' => (string) $client->id, 'source' => 'whmcs'],
        ];
        $resp = $this->api->createCustomer($body, 'customer-' . ($sandbox ? 's' : 'l') . '-' . $client->id . '-' . md5(strtolower($client->email)));
        if (empty($resp['customer_id'])) {
            throw new \RuntimeException('Customer creation returned no customer_id');
        }
        Store::saveCustomerId($clientId, $sandbox, $resp['customer_id']);
        return $resp['customer_id'];
    }

    // --------------------------------------------------------------- products

    /**
     * A recurring Bachs product for a given price and cadence. Products are
     * shared between customers with the same price/cycle and cached locally.
     */
    public function ensureProduct($amount, array $cadence)
    {
        $amount = Money::format($amount, self::SUBSCRIPTION_CURRENCY);
        $cacheKey = implode('|', [
            $this->config->isSandbox() ? 'sandbox' : 'live',
            self::SUBSCRIPTION_CURRENCY,
            $amount,
            $cadence['interval'],
            (int) $cadence['frequency'],
        ]);
        $existing = Store::productId($cacheKey);
        if ($existing) {
            return $existing;
        }

        $company = class_exists('\WHMCS\Config\Setting') ? (string) \WHMCS\Config\Setting::getValue('CompanyName') : '';
        $name = trim(($company ?: 'Hosting') . ' services');
        $resp = $this->api->createProduct([
            'name' => $name,
            'description' => 'Automatic renewal: ' . self::SUBSCRIPTION_CURRENCY . ' ' . $amount . ' ' . BillingCycle::label($cadence),
            'price' => [
                'currency' => self::SUBSCRIPTION_CURRENCY,
                'price_type' => 'fixed',
                'amount' => $amount,
            ],
            'billing_cycle' => [
                'interval' => $cadence['interval'],
                'frequency' => (int) $cadence['frequency'],
            ],
            'metadata' => ['source' => 'whmcs', 'cache_key' => $cacheKey],
        ], 'product-' . hash('sha256', $cacheKey));

        if (empty($resp['id'])) {
            throw new \RuntimeException('Product creation returned no id');
        }
        Store::saveProductId($cacheKey, $resp['id']);
        return $resp['id'];
    }

    public function portalUrl($clientId)
    {
        $customerId = Store::customerId($clientId, $this->config->isSandbox());
        if (!$customerId) {
            return null;
        }
        $resp = $this->api->createPortalSession($customerId, 'portal-' . $customerId . '-' . bin2hex(random_bytes(8)));
        return !empty($resp['url']) && self::isSafeUrl($resp['url']) ? $resp['url'] : null;
    }
}
