<?php
/**
 * Client area: view and manage Bachs auto-pay (saved card subscriptions).
 *
 *  - lists the client's auto-pay subscriptions and the services they cover
 *  - "Manage saved cards" opens the Bachs customer portal
 *  - "Turn off auto-pay" cancels the subscription at Bachs
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\BillingCycle;
use HarmonWeb\Bachs\CheckoutService;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use HarmonWeb\Bachs\SubscriptionService;
use WHMCS\Database\Capsule;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$clientId = 0;
if (class_exists('\WHMCS\Authentication\CurrentUser')) {
    $client = (new \WHMCS\Authentication\CurrentUser())->client();
    $clientId = $client ? (int) $client->id : 0;
} elseif (!empty($_SESSION['uid'])) {
    $clientId = (int) $_SESSION['uid'];
}

try {
    $config = Config::load();
    Schema::ensure();
} catch (\Throwable $e) {
    http_response_code(503);
    exit('Auto-pay management is unavailable.');
}
$base = $config->systemUrl();

if (!$clientId) {
    header('Location: ' . $base . 'login.php');
    exit;
}

if (empty($_SESSION['bachs_csrf'])) {
    $_SESSION['bachs_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['bachs_csrf'];
$e = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$flash = null;
$flashType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid request. Please go back and try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        $api = ApiClient::fromConfig($config);
        if ($action === 'portal') {
            $url = (new CheckoutService($config, $api))->portalUrl($clientId);
            if ($url) {
                header('Location: ' . $url, true, 303);
                exit;
            }
            $flash = 'No saved payment methods were found for your account.';
        } elseif ($action === 'cancel') {
            $subId = (string) ($_POST['subscription_id'] ?? '');
            $sub = $subId !== '' ? Store::subscription($subId) : null;
            if (!$sub || (int) $sub->client_id !== $clientId) {
                $flash = 'Subscription not found.';
                $flashType = 'danger';
            } else {
                (new SubscriptionService($config, $api))->cancel($subId, 'Turned off by client in client area');
                Logger::activity('Client turned off auto-pay ' . $subId, $clientId);
                $flash = 'Auto-pay has been turned off. Future invoices will need to be paid manually.';
                $flashType = 'success';
            }
        }
    } catch (\Throwable $ex) {
        Logger::activity('Auto-pay management action failed: ' . $ex->getMessage(), $clientId);
        $flash = 'Something went wrong. Please try again or contact support.';
        $flashType = 'danger';
    }
}

$subs = Store::subscriptionsForClient($clientId);

$labelFor = function ($item) {
    switch ($item['type']) {
        case 'Hosting':
            $r = Capsule::table('tblhosting as h')->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.id', $item['relid'])->select('p.name', 'h.domain')->first();
            return $r ? trim($r->name . ($r->domain ? ' (' . $r->domain . ')' : '')) : 'Service #' . $item['relid'];
        case 'Addon':
            $r = Capsule::table('tblhostingaddons')->where('id', $item['relid'])->value('name');
            return $r ?: 'Addon #' . $item['relid'];
        case 'Domain':
            $r = Capsule::table('tbldomains')->where('id', $item['relid'])->value('domain');
            return $r ?: 'Domain #' . $item['relid'];
    }
    return $item['type'] . ' #' . $item['relid'];
};

$company = class_exists('\WHMCS\Config\Setting') ? (string) \WHMCS\Config\Setting::getValue('CompanyName') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Automatic Payments<?php echo $company ? ' - ' . $e($company) : ''; ?></title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f5f6f8;color:#222;margin:0}
  .wrap{max-width:760px;margin:40px auto;padding:0 16px}
  .card{background:#fff;border:1px solid #e3e6ea;border-radius:8px;padding:20px;margin-bottom:16px}
  h1{font-size:22px;margin:0 0 6px} .muted{color:#667;font-size:14px}
  .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#eef;color:#335}
  .badge.active{background:#e6f6ea;color:#1d6b2f} .badge.past_due,.badge.unpaid{background:#fdecea;color:#8a1f11}
  .badge.canceled{background:#eee;color:#666}
  button{cursor:pointer;border-radius:6px;border:1px solid #ccd;background:#fff;padding:8px 14px;font-size:14px}
  button.primary{background:#1d6fe8;color:#fff;border-color:#1d6fe8} button.danger{color:#a12;border-color:#e3b6b6}
  .alert{padding:12px 14px;border-radius:6px;margin-bottom:16px} .alert.success{background:#e6f6ea}
  .alert.danger{background:#fdecea} .alert.info{background:#eef3fd}
  ul{margin:8px 0 12px 18px;padding:0} a{color:#1d6fe8}
</style>
</head>
<body>
<div class="wrap">
  <p><a href="<?php echo $e($base . 'clientarea.php'); ?>">&larr; Back to client area</a></p>
  <div class="card">
    <h1>Automatic payments</h1>
    <p class="muted">Services on auto-pay are renewed by charging your saved card through Bachs on each due date.</p>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
      <input type="hidden" name="action" value="portal">
      <button type="submit" class="primary">Manage saved cards</button>
    </form>
  </div>

  <?php if ($flash) { ?>
    <div class="alert <?php echo $e($flashType); ?>"><?php echo $e($flash); ?></div>
  <?php } ?>

  <?php if (!count($subs)) { ?>
    <div class="card"><p class="muted">You have no automatic payments set up. Choose <strong>"Pay &amp; enable auto-renew"</strong> when paying a renewal invoice to turn it on.</p></div>
  <?php } ?>

  <?php foreach ($subs as $sub) {
      $items = json_decode($sub->items, true) ?: [];
      $active = in_array($sub->status, SubscriptionService::ACTIVE_STATUSES, true); ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong><?php echo $e($sub->currency . ' ' . $sub->amount); ?> <?php echo $e(BillingCycle::label(['interval' => $sub->interval, 'frequency' => $sub->frequency])); ?></strong>
        <span class="badge <?php echo $e($sub->status); ?>"><?php echo $e(str_replace('_', ' ', $sub->status)); ?></span>
      </div>
      <ul>
        <?php foreach ($items as $item) { ?><li><?php echo $e($labelFor($item)); ?></li><?php } ?>
      </ul>
      <?php if ($active && $sub->next_billed_at) { ?>
        <p class="muted">Next charge: <?php echo $e(date('j M Y', strtotime($sub->next_billed_at))); ?></p>
      <?php } ?>
      <?php if ($sub->status === 'past_due') { ?>
        <p class="muted">Your last automatic payment failed. Update your card via "Manage saved cards" and we will retry.</p>
      <?php } ?>
      <?php if ($active) { ?>
        <form method="post" onsubmit="return confirm('Turn off auto-pay for these services?');">
          <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="subscription_id" value="<?php echo $e($sub->subscription_id); ?>">
          <button type="submit" class="danger">Turn off auto-pay</button>
        </form>
      <?php } ?>
    </div>
  <?php } ?>
</div>
</body>
</html>
