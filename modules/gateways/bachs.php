<?php
/**
 * Bachs Payment Gateway for WHMCS
 *
 * Hosted checkout via Bachs (https://bachs.io): cards, crypto, bank transfer
 * and mobile money in multiple currencies, plus card-on-file auto-pay for
 * service renewals using Bachs subscriptions.
 *
 * Built for Harmonweb.
 *
 * Files:
 *   modules/gateways/bachs.php                  this file (settings, pay button, refunds, cancellations)
 *   modules/gateways/callback/bachs.php         webhook endpoint
 *   modules/gateways/bachs/pay.php              creates the checkout session and redirects
 *   modules/gateways/bachs/return.php           customer return URL (instant verification)
 *   modules/gateways/bachs/manage.php           client area: auto-pay status, manage cards, cancel
 *   modules/gateways/bachs/admin.php            admin: re-verify an invoice now
 *   modules/gateways/bachs/cron.php             CLI: reconcile / status / register-webhook
 *   includes/hooks/bachs.php                    cron reconciliation + UI hooks
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bachs/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\ApiException;
use HarmonWeb\Bachs\CheckoutService;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Money;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use HarmonWeb\Bachs\SubscriptionService;
use HarmonWeb\Bachs\Whmcs;

function bachs_MetaData()
{
    return [
        'DisplayName' => 'Bachs (Card, Crypto, Bank Transfer, Mobile Money)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function bachs_config()
{
    $systemUrl = '';
    if (class_exists('\WHMCS\Config\Setting')) {
        $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/';
    }
    $webhookUrl = $systemUrl . 'modules/gateways/callback/bachs.php';

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Bachs',
        ],
        'setupInfo' => [
            'FriendlyName' => 'Setup',
            'Type' => 'System',
            'Description' => '<div style="line-height:1.6">'
                . '<strong>Webhook URL:</strong> <code>' . htmlspecialchars($webhookUrl) . '</code><br>'
                . 'Create a webhook endpoint in the Bachs dashboard (or run <code>php modules/gateways/bachs/cron.php register-webhook</code>) '
                . 'and paste its signing secret below.<br>'
                . '<strong>Reconciliation:</strong> runs automatically with the WHMCS cron. '
                . 'Optional dedicated cron: <code>*/10 * * * * php -q ' . htmlspecialchars(ROOTDIR) . '/modules/gateways/bachs/cron.php reconcile</code><br>'
                . '<strong>Hooks:</strong> make sure <code>includes/hooks/bachs.php</code> is uploaded.'
                . '</div>',
        ],
        'liveSecretKey' => [
            'FriendlyName' => 'Live Secret Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'sk_live_... from Bachs Dashboard &gt; Developers &gt; API keys',
        ],
        'liveWebhookSecret' => [
            'FriendlyName' => 'Live Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'whsec_... signing secret of the live webhook endpoint',
        ],
        'sandboxSecretKey' => [
            'FriendlyName' => 'Sandbox Secret Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'sk_sandbox_...',
        ],
        'sandboxWebhookSecret' => [
            'FriendlyName' => 'Sandbox Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '60',
        ],
        'testMode' => [
            'FriendlyName' => 'Sandbox Mode',
            'Type' => 'yesno',
            'Description' => 'Use the Bachs sandbox (https://sandbox-api.bachs.io). No real money moves.',
        ],
        'allowCard' => [
            'FriendlyName' => 'Accept Cards',
            'Type' => 'yesno',
            'Default' => 'on',
        ],
        'allowCrypto' => [
            'FriendlyName' => 'Accept Crypto',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Stablecoins and other crypto supported by your Bachs account',
        ],
        'allowBankTransfer' => [
            'FriendlyName' => 'Accept Bank Transfer',
            'Type' => 'yesno',
            'Default' => 'on',
        ],
        'allowMobileMoney' => [
            'FriendlyName' => 'Accept Mobile Money',
            'Type' => 'yesno',
            'Default' => 'on',
        ],
        'enableAutopay' => [
            'FriendlyName' => 'Enable Card Auto-Pay',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Offer "Pay &amp; enable auto-renew" on renewal invoices. The card is saved by Bachs and charged automatically every billing cycle.',
        ],
        'autopayConvert' => [
            'FriendlyName' => 'Auto-Pay for non-USD Invoices',
            'Type' => 'yesno',
            'Description' => 'Bachs subscriptions are billed in USD. Tick to allow auto-pay for clients billed in other currencies, converted at your WHMCS exchange rates (USD must exist under Setup &gt; Currencies).',
        ],
        'priceSync' => [
            'FriendlyName' => 'Sync Auto-Pay Price',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Update the subscription amount for the next cycle when the service price changes (upgrades, price changes, exchange-rate drift).',
        ],
        'priceSyncThreshold' => [
            'FriendlyName' => 'Price Sync Threshold (%)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '1',
        ],
        'checkoutExpiry' => [
            'FriendlyName' => 'Checkout Expiry (minutes)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '60',
            'Description' => '15 - 1440. Crypto and bank transfers may need longer windows.',
        ],
        'reconcileDays' => [
            'FriendlyName' => 'Reconciliation Lookback (days)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '7',
            'Description' => 'How far back the payment validation cron re-checks checkouts and payments.',
        ],
        'unmatchedCreditDays' => [
            'FriendlyName' => 'Credit Unmatched Renewals After (days)',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '3',
            'Description' => 'An auto-pay charge with no unpaid renewal invoice is added to the client\'s credit balance after this many days. 0 = never (admin review only).',
        ],
        'buttonText' => [
            'FriendlyName' => 'Pay Button Text',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'Pay Now',
        ],
        'debugLogging' => [
            'FriendlyName' => 'Debug Logging',
            'Type' => 'yesno',
            'Description' => 'Log API requests/responses to the Module Log (secrets are masked).',
        ],
    ];
}

/**
 * Validate settings when the admin saves them.
 */
function bachs_config_validate(array $params)
{
    $config = new Config($params);
    try {
        $config->validateKey();
        $api = new ApiClient($config->secretKey(), $config->baseUrl());
        $api->supportedCurrencies();
    } catch (ApiException $e) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration('Bachs rejected the API key: ' . $e->getMessage());
    } catch (\RuntimeException $e) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration($e->getMessage());
    }
    if ($config->webhookSecret() === '') {
        throw new \WHMCS\Exception\Module\InvalidConfiguration('Enter the webhook signing secret for the ' . ($config->isSandbox() ? 'sandbox' : 'live') . ' endpoint. Payments cannot be confirmed securely without it.');
    }
    try {
        Schema::ensure();
    } catch (\Exception $e) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration('Could not create the module database tables: ' . $e->getMessage());
    }
}

/**
 * Payment button(s) on the invoice page.
 */
function bachs_link($params)
{
    try {
        Schema::ensure();
        $config = Config::fromParams($params);
        $invoice = Whmcs::invoice($params['invoiceid']);
        if (!$invoice || $invoice->status !== 'Unpaid' || $invoice->balance <= 0) {
            return '';
        }

        $action = $config->systemUrl() . 'modules/gateways/bachs/pay.php';
        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $form = function ($mode, $label, $class) use ($config, $invoice, $action, $e) {
            return '<form method="post" action="' . $e($action) . '" style="display:inline-block;margin:4px 0">'
                . '<input type="hidden" name="invoiceid" value="' . (int) $invoice->id . '">'
                . '<input type="hidden" name="mode" value="' . $e($mode) . '">'
                . '<input type="hidden" name="token" value="' . $e(CheckoutService::formToken($config, $invoice->id, $mode)) . '">'
                . '<button type="submit" class="' . $e($class) . '">' . $e($label) . '</button>'
                . '</form>';
        };

        $html = '<div class="bachs-pay">';
        $html .= $form(CheckoutService::MODE_ONE_TIME, $config->buttonText(), 'btn btn-success');

        $notice = bachs_autopay_notice($invoice);
        if ($notice) {
            $html .= '<p class="small text-muted" style="margin-top:6px">' . $e($notice) . '</p>';
        } elseif ($config->autopayEnabled()) {
            // Quoting only reads WHMCS data; no API call happens on page render.
            $api = new ApiClient($config->secretKey(), $config->baseUrl());
            $quote = (new CheckoutService($config, $api))->quoteSubscription($invoice);
            if (!empty($quote['eligible'])) {
                $html .= '<br>' . $form(CheckoutService::MODE_SUBSCRIPTION, 'Pay & enable auto-renew (card)', 'btn btn-default btn-sm');
                $charge = $quote['currency'] . ' ' . $quote['amount'];
                $html .= '<p class="small text-muted" style="margin-top:6px">Auto-renew saves your card with Bachs and charges '
                    . $e($charge) . ' ' . $e(\HarmonWeb\Bachs\BillingCycle::label($quote['cadence']))
                    . '. Cancel any time from your client area.</p>';
            }
        }
        $html .= '</div>';
        return $html;
    } catch (\Throwable $ex) {
        Logger::activity('Pay button error for invoice #' . (int) $params['invoiceid'] . ': ' . $ex->getMessage());
        return '<p class="text-danger">This payment method is temporarily unavailable. Please try again later or choose another method.</p>';
    }
}

/**
 * Message shown when the invoice items are already on auto-pay.
 */
function bachs_autopay_notice($invoice)
{
    $items = \WHMCS\Database\Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice->id)->get();
    foreach ($items as $item) {
        $type = in_array($item->type, ['DomainRegister', 'DomainTransfer'], true) ? 'Domain' : $item->type;
        $sub = Store::subscriptionForItem($type, $item->relid);
        if ($sub && in_array($sub->status, ['active', 'trialing', 'past_due'], true)) {
            $when = $sub->next_billed_at ? date('j M Y', strtotime($sub->next_billed_at)) : 'the renewal date';
            return 'Auto-pay is active for this service: your saved card will be charged automatically on ' . $when
                . '. You only need to pay manually if you prefer another method.';
        }
    }
    return null;
}

/**
 * Refund (full or partial) from the WHMCS admin area.
 */
function bachs_refund($params)
{
    $config = Config::fromParams($params);
    $transId = (string) $params['transid'];
    try {
        Schema::ensure();
        $api = ApiClient::fromConfig($config);

        $payment = Store::paymentById($transId);
        $body = [
            'charge_id' => $transId,
            'reference' => 'WHMCS-RF-' . (int) $params['invoiceid'] . '-' . bin2hex(random_bytes(5)),
            'reason' => 'Refund for invoice #' . (int) $params['invoiceid'],
        ];

        // Partial refunds must be expressed in the charge currency.
        $invoiceCurrency = Money::normalizeCurrency($params['currency']);
        $fullAmount = $payment && $payment->applied_amount !== null ? (float) $payment->applied_amount : null;
        $refundAmount = (float) $params['amount'];
        $isPartial = $fullAmount === null || Money::compare($refundAmount, $fullAmount) < 0;
        if ($isPartial) {
            $chargeCurrency = $payment ? Money::normalizeCurrency($payment->currency) : $invoiceCurrency;
            if ($chargeCurrency === $invoiceCurrency) {
                $body['amount'] = Money::format($refundAmount, $chargeCurrency);
            } elseif ($payment && $fullAmount) {
                $ratio = $refundAmount / $fullAmount;
                $body['amount'] = Money::format((float) $payment->amount * $ratio, $chargeCurrency);
            } else {
                return ['status' => 'error', 'rawdata' => 'Cannot determine the charge currency for a partial refund.'];
            }
        }

        $idempotency = 'refund-' . $transId . '-' . md5($body['amount'] ?? 'full') . '-' . date('YmdHi');
        $resp = $api->createRefund($body, $idempotency);
        $status = strtolower((string) ($resp['status'] ?? ''));

        if (in_array($status, ['processing', 'success', 'succeeded', 'pending'], true)) {
            return [
                'status' => 'success',
                'rawdata' => $resp,
                'transid' => (string) ($resp['refund_id'] ?? $body['reference']),
                'fees' => isset($resp['refund_fee_amount']) && $invoiceCurrency === 'USD' ? (float) $resp['refund_fee_amount'] : 0,
            ];
        }
        return ['status' => 'declined', 'rawdata' => $resp];
    } catch (\Exception $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }
}

/**
 * Called by WHMCS when an admin clicks "Cancel Subscription" or a service
 * with a subscription is cancelled/terminated.
 */
function bachs_cancelSubscription($params)
{
    $subscriptionId = (string) $params['subscriptionID'];
    try {
        Schema::ensure();
        $config = Config::fromParams($params);
        $service = new SubscriptionService($config, ApiClient::fromConfig($config));
        $service->cancel($subscriptionId, 'Cancelled from WHMCS');
        return ['status' => 'success', 'rawdata' => ['subscription_id' => $subscriptionId]];
    } catch (\Exception $e) {
        return ['status' => 'error', 'rawdata' => $e->getMessage()];
    }
}

/**
 * Transaction details popup in the admin area (WHMCS 7.5+).
 */
function bachs_TransactionInformation(array $params = [])
{
    $config = Config::fromParams($params);
    $api = ApiClient::fromConfig($config);
    $payment = $api->getPayment($params['transactionId']);

    $info = new \WHMCS\Billing\Payment\Transaction\Information();
    $info->setTransactionId($params['transactionId'])
        ->setAmount((float) ($payment['amount'] ?? 0))
        ->setCurrency((string) ($payment['currency'] ?? ''))
        ->setType((string) ($payment['billing_reason'] ?? 'purchase'))
        ->setStatus((string) ($payment['status'] ?? ''))
        ->setDescription((string) ($payment['message'] ?? ''))
        ->setAdditionalDatum('Payment Method', (string) ($payment['payment_method'] ?? ''))
        ->setAdditionalDatum('Checkout', (string) ($payment['checkout_id'] ?? ''))
        ->setAdditionalDatum('Subscription', (string) ($payment['subscription_id'] ?? ''))
        ->setAdditionalDatum('Fee (USD)', (string) ($payment['fee_usd'] ?? ''));
    if (!empty($payment['created_at'])) {
        $info->setCreated(\WHMCS\Carbon::parse($payment['created_at']));
    }
    if (isset($payment['fee_usd']) && Money::normalizeCurrency($payment['currency'] ?? '') === 'USD') {
        $info->setFee((float) $payment['fee_usd']);
    }
    return $info;
}
