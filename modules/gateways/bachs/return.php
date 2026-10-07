<?php
/**
 * Customer return URL (success_url). Bachs appends ?checkout_id=...
 *
 * Verifies the checkout against the Bachs API immediately so the customer
 * usually sees their invoice as Paid without waiting for the webhook. The
 * query string is only used to find our own record; payment state always
 * comes from the API.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\PaymentProcessor;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use WHMCS\Database\Capsule;

header('Cache-Control: no-store');

try {
    $config = Config::load();
    Schema::ensure();
} catch (\Throwable $e) {
    header('Location: ../../../clientarea.php?action=invoices');
    exit;
}
$base = $config->systemUrl();

$checkoutId = (string) ($_GET['checkout_id'] ?? '');
// Some providers append "?checkout_id" even when a query already exists; be lenient.
if ($checkoutId === '' && !empty($_SERVER['QUERY_STRING']) && preg_match('/checkout_id=([A-Za-z0-9_\-]+)/', $_SERVER['QUERY_STRING'], $m)) {
    $checkoutId = $m[1];
}

$row = null;
if ($checkoutId !== '' && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $checkoutId)) {
    $row = Store::checkoutByCheckoutId($checkoutId);
}
if (!$row && !empty($_SESSION['bachs_last_invoice'])) {
    $row = Capsule::table(Schema::T_CHECKOUTS)
        ->where('invoice_id', (int) $_SESSION['bachs_last_invoice'])
        ->whereNotNull('checkout_id')
        ->orderBy('id', 'desc')
        ->first();
}
if (!$row) {
    header('Location: ' . $base . 'clientarea.php?action=invoices');
    exit;
}

$invoiceUrl = $base . 'viewinvoice.php?id=' . (int) $row->invoice_id;
$outcome = 'pending';
try {
    $processor = new PaymentProcessor($config, ApiClient::fromConfig($config));
    $outcome = $processor->processCheckout($row, 'return');
} catch (\Throwable $e) {
    // The webhook and cron will finish the job; never show the customer an error here.
    Logger::activity('Return-page verification deferred for invoice #' . $row->invoice_id . ': ' . $e->getMessage(), $row->client_id);
}
unset($_SESSION['bachs_last_invoice']);

switch ($outcome) {
    case 'applied':
    case 'already_applied':
    case 'credited':
        $suffix = '&paymentsuccess=true';
        break;
    case 'failed':
    case 'expired':
    case 'cancelled':
        $suffix = '&paymentfailed=true';
        break;
    default:
        // Crypto/bank transfers can take a while to confirm.
        $suffix = '&paymentinititated=true';
}
header('Location: ' . $invoiceUrl . $suffix, true, 303);
exit;
