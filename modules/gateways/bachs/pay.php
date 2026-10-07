<?php
/**
 * Starts a Bachs hosted checkout for an invoice and redirects the customer.
 *
 * Reached only by POST from the button rendered by bachs_link(). The form
 * carries an HMAC token bound to the invoice and mode, so checkout sessions
 * cannot be minted for arbitrary invoices.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\CheckoutService;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Whmcs;
use WHMCS\Database\Capsule;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$invoiceId = (int) ($_POST['invoiceid'] ?? 0);

function bachs_pay_fail($message, $invoiceId)
{
    http_response_code(400);
    $systemUrl = '';
    try {
        $systemUrl = Config::load()->systemUrl();
    } catch (\Throwable $e) {
    }
    $link = htmlspecialchars($systemUrl . 'viewinvoice.php?id=' . (int) $invoiceId, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Payment</title></head><body style="font-family:sans-serif;max-width:520px;margin:60px auto;padding:0 16px">'
        . '<h2>Payment could not be started</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . $link . '">Return to invoice</a></p></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $invoiceId <= 0) {
    bachs_pay_fail('Invalid request.', $invoiceId);
}

try {
    $config = Config::load();
    Schema::ensure();
} catch (\Throwable $e) {
    bachs_pay_fail('This payment method is currently unavailable.', $invoiceId);
}

$mode = ($_POST['mode'] ?? '') === CheckoutService::MODE_SUBSCRIPTION
    ? CheckoutService::MODE_SUBSCRIPTION
    : CheckoutService::MODE_ONE_TIME;

if (!CheckoutService::verifyFormToken($config, $invoiceId, $mode, $_POST['token'] ?? '')) {
    bachs_pay_fail('This payment link has expired. Please reload the invoice and try again.', $invoiceId);
}

$invoice = Whmcs::invoice($invoiceId);
if (!$invoice) {
    bachs_pay_fail('Invoice not found.', $invoiceId);
}

// A logged-in client may only pay their own invoices.
$loggedInClient = 0;
if (class_exists('\WHMCS\Authentication\CurrentUser')) {
    $client = (new \WHMCS\Authentication\CurrentUser())->client();
    $loggedInClient = $client ? (int) $client->id : 0;
} elseif (!empty($_SESSION['uid'])) {
    $loggedInClient = (int) $_SESSION['uid'];
}
if ($loggedInClient && $loggedInClient !== (int) $invoice->userid && empty($_SESSION['adminid'])) {
    bachs_pay_fail('This invoice belongs to a different account.', $invoiceId);
}

// Abuse guard: cap new sessions per invoice per hour (reuse covers normal retries).
$recent = Capsule::table(Schema::T_CHECKOUTS)
    ->where('invoice_id', $invoiceId)
    ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
    ->count();
if ($recent >= 15) {
    bachs_pay_fail('Too many payment attempts. Please wait a few minutes and try again.', $invoiceId);
}

try {
    $api = ApiClient::fromConfig($config);
    $url = (new CheckoutService($config, $api))->start($invoiceId, $mode);
} catch (\RuntimeException $e) {
    Logger::activity('Checkout start failed for invoice #' . $invoiceId . ': ' . $e->getMessage(), $invoice->userid);
    bachs_pay_fail($e->getMessage(), $invoiceId);
} catch (\Throwable $e) {
    Logger::activity('Checkout start error for invoice #' . $invoiceId . ': ' . $e->getMessage(), $invoice->userid);
    bachs_pay_fail('We could not start the payment right now. Please try again shortly.', $invoiceId);
}

$_SESSION['bachs_last_invoice'] = $invoiceId;
header('Location: ' . $url, true, 303);
exit;
