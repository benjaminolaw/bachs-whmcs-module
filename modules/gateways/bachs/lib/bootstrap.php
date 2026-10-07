<?php
/**
 * Bachs gateway library loader.
 *
 * Every entry point (gateway module, callback, hooks, cron, client pages)
 * requires this file once. Classes are plain PHP with no Composer dependency
 * so the module can be dropped into any WHMCS installation.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('BACHS_MODULE_VERSION')) {
    define('BACHS_MODULE_VERSION', '1.0.0');
    define('BACHS_GATEWAY', 'bachs');

    require_once __DIR__ . '/ApiException.php';
    require_once __DIR__ . '/Money.php';
    require_once __DIR__ . '/BillingCycle.php';
    require_once __DIR__ . '/Config.php';
    require_once __DIR__ . '/Logger.php';
    require_once __DIR__ . '/ApiClient.php';
    require_once __DIR__ . '/Schema.php';
    require_once __DIR__ . '/Store.php';
    require_once __DIR__ . '/Whmcs.php';
    require_once __DIR__ . '/WebhookVerifier.php';
    require_once __DIR__ . '/InvoiceAnalyzer.php';
    require_once __DIR__ . '/CheckoutService.php';
    require_once __DIR__ . '/PaymentProcessor.php';
    require_once __DIR__ . '/SubscriptionService.php';
    require_once __DIR__ . '/WebhookHandler.php';
    require_once __DIR__ . '/Reconciler.php';
}
