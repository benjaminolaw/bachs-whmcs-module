<?php
/**
 * Bachs gateway hooks.
 *
 *  - AfterCronJob:                    payment validation / reconciliation pass
 *  - ClientAreaProductDetailsOutput:  auto-pay status on the service page
 *  - ClientAreaPrimaryNavbar:         "Automatic Payments" link under Billing
 *  - AdminInvoicesControlsOutput:     "Re-check Bachs payment" button on invoices
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!file_exists(ROOTDIR . '/modules/gateways/bachs/lib/bootstrap.php')) {
    return;
}
require_once ROOTDIR . '/modules/gateways/bachs/lib/bootstrap.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\Config;
use HarmonWeb\Bachs\Logger;
use HarmonWeb\Bachs\Reconciler;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use WHMCS\Database\Capsule;

/** Gateway config if the module is active, else null. */
function bachs_hook_config()
{
    try {
        return Config::load();
    } catch (\Throwable $e) {
        return null;
    }
}

add_hook('AfterCronJob', 1, function ($vars) {
    $config = bachs_hook_config();
    if (!$config || $config->secretKey() === '') {
        return;
    }
    try {
        if (!function_exists('addInvoicePayment')) {
            require_once ROOTDIR . '/includes/invoicefunctions.php';
        }
        Schema::ensure();
        (new Reconciler($config, ApiClient::fromConfig($config)))->run();
    } catch (\Throwable $e) {
        Logger::activity('Reconciliation failed: ' . $e->getMessage());
    }
});

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    $config = bachs_hook_config();
    if (!$config || !$config->autopayEnabled() || empty($vars['service'])) {
        return '';
    }
    try {
        Schema::ensure();
        $sub = Store::subscriptionForItem('Hosting', (int) $vars['service']->id);
        $url = htmlspecialchars($config->systemUrl() . 'modules/gateways/bachs/manage.php', ENT_QUOTES, 'UTF-8');
        if (!$sub) {
            return '';
        }
        $next = $sub->next_billed_at ? date('j M Y', strtotime($sub->next_billed_at)) : null;
        $state = $sub->status === 'past_due'
            ? '<strong>Auto-pay: last charge failed.</strong> Please update your card.'
            : '<strong>Auto-pay is on.</strong>' . ($next ? ' Next charge on ' . htmlspecialchars($next) . '.' : '');
        return '<div class="alert alert-' . ($sub->status === 'past_due' ? 'warning' : 'info') . '">'
            . $state . ' <a href="' . $url . '">Manage automatic payments</a></div>';
    } catch (\Throwable $e) {
        return '';
    }
});

add_hook('ClientAreaPrimaryNavbar', 1, function ($navbar) {
    $config = bachs_hook_config();
    if (!$config || !$config->autopayEnabled()) {
        return;
    }
    $billing = $navbar->getChild('Billing');
    if ($billing && is_null($billing->getChild('Bachs Auto-Pay'))) {
        $billing->addChild('Bachs Auto-Pay', [
            'label' => 'Automatic Payments',
            'uri' => $config->systemUrl() . 'modules/gateways/bachs/manage.php',
            'order' => 95,
        ]);
    }
});

add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $config = bachs_hook_config();
    $adminId = (int) ($_SESSION['adminid'] ?? 0);
    if (!$config || !$adminId) {
        return '';
    }
    try {
        Schema::ensure();
        $invoiceId = (int) $vars['invoiceid'];
        $count = Capsule::table(Schema::T_CHECKOUTS)->where('invoice_id', $invoiceId)->whereNotNull('checkout_id')->count();
        if (!$count) {
            return '';
        }
        $token = hash_hmac('sha256', 'admin-verify|' . $invoiceId . '|' . $adminId, $config->signingKey());
        $action = htmlspecialchars($config->systemUrl() . 'modules/gateways/bachs/admin.php', ENT_QUOTES, 'UTF-8');
        return '<form method="post" action="' . $action . '" style="display:inline-block;margin-left:6px">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . '<input type="hidden" name="token" value="' . $token . '">'
            . '<button type="submit" class="btn btn-default">Re-check Bachs payment (' . $count . ')</button>'
            . '</form>';
    } catch (\Throwable $e) {
        return '';
    }
});
