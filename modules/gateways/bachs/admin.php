<?php
/**
 * Admin tool: re-verify all Bachs checkouts for an invoice right now.
 * Linked from the invoice page (AdminInvoicesControlsOutput hook).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Reconciler;
use HarmonWeb\Bachs\Schema;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$adminId = (int) ($_SESSION['adminid'] ?? 0);
if (!$adminId) {
    http_response_code(403);
    exit('Admin login required.');
}

$invoiceId = (int) ($_POST['invoiceid'] ?? 0);
try {
    $config = Config::load();
    Schema::ensure();
} catch (\Throwable $e) {
    exit('Bachs gateway is not configured: ' . htmlspecialchars($e->getMessage()));
}

$expected = hash_hmac('sha256', 'admin-verify|' . $invoiceId . '|' . $adminId, $config->signingKey());
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$invoiceId || !hash_equals($expected, (string) ($_POST['token'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request.');
}

$results = (new Reconciler($config, ApiClient::fromConfig($config)))->verifyInvoice($invoiceId);
Logger::activity('Admin #' . $adminId . ' re-verified Bachs payments for invoice #' . $invoiceId . ': ' . json_encode($results));

global $customadminpath;
$adminPath = $customadminpath ?: 'admin';
$back = $config->systemUrl() . $adminPath . '/invoices.php?action=edit&id=' . $invoiceId;
$e = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?><!doctype html>
<html><head><meta charset="utf-8"><title>Bachs verification</title></head>
<body style="font-family:sans-serif;max-width:680px;margin:40px auto;padding:0 16px">
<h2>Bachs verification for invoice #<?php echo (int) $invoiceId; ?></h2>
<?php if (!$results) { ?>
  <p>No Bachs checkouts were created for this invoice.</p>
<?php } else { ?>
  <table cellpadding="6" style="border-collapse:collapse">
    <tr><th align="left">Checkout</th><th align="left">Result</th></tr>
    <?php foreach ($results as $checkout => $outcome) { ?>
      <tr><td><code><?php echo $e($checkout); ?></code></td><td><?php echo $e($outcome); ?></td></tr>
    <?php } ?>
  </table>
<?php } ?>
<p><a href="<?php echo $e($back); ?>">Back to invoice</a></p>
</body></html>
