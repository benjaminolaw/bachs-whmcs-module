<?php
/**
 * Bachs command-line tools.
 *
 *   php modules/gateways/bachs/cron.php reconcile          run the payment validation pass now
 *   php modules/gateways/bachs/cron.php status             show last run + pending counts
 *   php modules/gateways/bachs/cron.php verify <invoiceid> re-verify one invoice
 *   php modules/gateways/bachs/cron.php autopay-check <id> explain whether auto-renew is offered
 *   php modules/gateways/bachs/cron.php register-webhook   create the webhook endpoint at Bachs
 *
 * Reconciliation already runs with the WHMCS cron via includes/hooks/bachs.php;
 * schedule this script separately only if you want a tighter interval:
 *   *\/10 * * * * php -q /path/to/whmcs/modules/gateways/bachs/cron.php reconcile
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Reconciler;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use HarmonWeb\Bachs\WebhookHandler;
use WHMCS\Database\Capsule;

$command = $argv[1] ?? 'reconcile';
$out = function ($line) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL);
};

try {
    $config = Config::load();
    Schema::ensure();
    $api = ApiClient::fromConfig($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$out('Mode: ' . ($config->isSandbox() ? 'SANDBOX' : 'LIVE'));

switch ($command) {
    case 'reconcile':
        $reconciler = new Reconciler($config, $api, $out);
        $reconciler->throttleSweep = false;
        $stats = $reconciler->run();
        $out('Done: ' . json_encode($stats));
        exit(empty($stats['errors']) ? 0 : 1);

    case 'verify':
        $invoiceId = (int) ($argv[2] ?? 0);
        if (!$invoiceId) {
            fwrite(STDERR, "Usage: cron.php verify <invoiceid>\n");
            exit(2);
        }
        foreach ((new Reconciler($config, $api, $out))->verifyInvoice($invoiceId) as $checkout => $outcome) {
            $out($checkout . ': ' . $outcome);
        }
        exit(0);

    case 'autopay-check':
        $invoiceId = (int) ($argv[2] ?? 0);
        $invoice = $invoiceId ? \HarmonWeb\Bachs\Whmcs::invoice($invoiceId) : null;
        if (!$invoice) {
            fwrite(STDERR, "Usage: cron.php autopay-check <invoiceid>\n");
            exit(2);
        }
        $out(sprintf('Invoice #%d: status %s, balance %s %s', $invoice->id, $invoice->status, $invoice->balance, $invoice->currency_code));
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get() as $line) {
            $out(sprintf('  line: type=%s relid=%d amount=%s  %s', $line->type, $line->relid, $line->amount, mb_substr($line->description, 0, 60)));
        }
        $quote = (new \HarmonWeb\Bachs\CheckoutService($config, $api))->quoteSubscription($invoice);
        if (!empty($quote['eligible'])) {
            $out('ELIGIBLE: auto-renew would charge ' . $quote['currency'] . ' ' . $quote['amount'] . ' ' . \HarmonWeb\Bachs\BillingCycle::label($quote['cadence']));
        } else {
            $out('NOT ELIGIBLE: ' . ($quote['reason'] ?? 'unknown'));
        }
        exit(0);

    case 'status':
        $out('Last reconcile: ' . (Store::kvGet('last_reconcile') ?: 'never'));
        foreach (['open', 'underpaid', 'error', 'mismatch'] as $s) {
            $out(sprintf('Checkouts %-10s %d', $s . ':', Capsule::table(Schema::T_CHECKOUTS)->where('status', $s)->count()));
        }
        foreach (['unmatched', 'error', 'processing'] as $s) {
            $out(sprintf('Payments  %-10s %d', $s . ':', Capsule::table(Schema::T_PAYMENTS)->where('status', $s)->count()));
        }
        $out('Active subscriptions: ' . Capsule::table(Schema::T_SUBSCRIPTIONS)->whereIn('status', ['active', 'trialing', 'past_due'])->count());
        $out('Failed webhook events (24h): ' . Capsule::table(Schema::T_EVENTS)->where('status', 'failed')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count());
        exit(0);

    case 'register-webhook':
        $url = $config->systemUrl() . 'modules/gateways/callback/bachs.php';
        if (strpos($url, 'https://') !== 0) {
            fwrite(STDERR, "Your WHMCS System URL must use https:// for webhooks.\n");
            exit(2);
        }
        $endpoints = $api->listWebhookEndpoints();
        if (isset($endpoints['items']) && is_array($endpoints['items'])) {
            $endpoints = $endpoints['items'];
        }
        foreach ((array) $endpoints as $endpoint) {
            if (is_array($endpoint) && ($endpoint['url'] ?? '') === $url) {
                $out('An endpoint for ' . $url . ' already exists (' . ($endpoint['endpoint_id'] ?? '?') . ').');
                $out('Copy its signing secret from the Bachs dashboard into the gateway settings.');
                exit(0);
            }
        }
        $resp = $api->createWebhookEndpoint('WHMCS', $url, WebhookHandler::SUBSCRIBED_EVENTS, 'webhook-' . md5($url));
        $out('Created endpoint ' . ($resp['endpoint_id'] ?? '?') . ' -> ' . $url);
        $out('Signing secret (shown once, paste into "' . ($config->isSandbox() ? 'Sandbox' : 'Live') . ' Webhook Signing Secret"):');
        fwrite(STDOUT, ($resp['signing_secret'] ?? '(none returned)') . PHP_EOL);
        exit(0);

    default:
        fwrite(STDERR, "Unknown command. Use: reconcile | status | verify <invoiceid> | autopay-check <invoiceid> | register-webhook\n");
        exit(2);
}
