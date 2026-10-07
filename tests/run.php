<?php
/**
 * End-to-end tests for the Bachs WHMCS gateway.
 *
 *   cd tests && composer install && php run.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeBachs.php';
require __DIR__ . '/../modules/gateways/bachs.php';

use HarmonWeb\Bachs\ApiClient;
use HarmonWeb\Bachs\BillingCycle;
use HarmonWeb\Bachs\CheckoutService;
use HarmonWeb\Bachs\Money;
use HarmonWeb\Bachs\PaymentProcessor;
use HarmonWeb\Bachs\Reconciler;
use HarmonWeb\Bachs\Schema;
use HarmonWeb\Bachs\Store;
use HarmonWeb\Bachs\SubscriptionService;
use HarmonWeb\Bachs\WebhookHandler;
use HarmonWeb\Bachs\WebhookVerifier;
use WHMCS\Database\Capsule;

$tests = [];
$failures = 0;

function test($name, callable $fn)
{
    $GLOBALS['tests'][$name] = $fn;
}

function check($cond, $message)
{
    if (!$cond) {
        throw new \Exception('Assertion failed: ' . $message);
    }
}

function eq($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new \Exception('Assertion failed: ' . $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

/** Fresh DB + fake API + config. Returns [config, fake, api]. */
function setup(array $config = [])
{
    bachs_test_reset_db();
    $cfg = bachs_test_config($config);
    $fake = new FakeBachs();
    ApiClient::$defaultTransport = $fake;
    $api = new ApiClient($cfg->secretKey(), $cfg->baseUrl(), $fake);
    return [$cfg, $fake, $api];
}

function webhook($cfg, $api, array $event, $secret = null, $ts = null)
{
    $raw = json_encode($event);
    $ts = $ts ?: time();
    $sig = WebhookVerifier::sign($ts, $raw, $secret ?: $cfg->webhookSecret());
    return (new WebhookHandler($cfg, $api))->handle($raw, ['x-bachs-signature' => $sig, 'x-bachs-timestamp' => (string) $ts]);
}

function invoiceStatus($id)
{
    return Capsule::table('tblinvoices')->where('id', $id)->value('status');
}

function paymentsFor($invoiceId)
{
    return Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->get();
}

/** USD client with a monthly $10 hosting service and its renewal invoice. */
function usdHosting($amount = 10.00, $cycle = 'Monthly')
{
    $hid = Capsule::table('tblhosting')->insertGetId([
        'userid' => 2, 'packageid' => 1, 'domain' => 'sam.test', 'billingcycle' => $cycle, 'amount' => $amount,
    ]);
    $inv = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => $amount]]);
    return [$hid, $inv];
}

// =========================================================== pure helpers

test('money compares in minor units', function () {
    check(Money::equals('10.00', 10), 'string vs float');
    check(Money::equals('0.10', 0.1 + 0.2 - 0.2), 'float drift');
    eq(-1, Money::compare('9.99', '10.00'), 'less');
    eq('15000.00', Money::format(15000, 'NGN'), 'NGN 2dp');
    eq('5000', Money::format(5000.4, 'UGX'), 'UGX 0dp');
});

test('billing cycles map to Bachs cadences', function () {
    eq(['interval' => 'month', 'frequency' => 3], BillingCycle::fromWhmcs('Quarterly'), 'quarterly');
    eq(['interval' => 'year', 'frequency' => 2], BillingCycle::fromWhmcs('Biennially'), 'biennially');
    eq(null, BillingCycle::fromWhmcs('One Time'), 'one time');
    eq(null, BillingCycle::fromWhmcs('Free Account'), 'free');
    eq(['interval' => 'year', 'frequency' => 1], BillingCycle::fromDomainYears(1), 'domain');
});

test('webhook signature verification', function () {
    $raw = '{"id":"evt_1"}';
    $ts = 1700000000;
    $sig = WebhookVerifier::sign($ts, $raw, 'whsec_x');
    check(WebhookVerifier::verify($raw, $sig, (string) $ts, 'whsec_x', $ts), 'valid');
    check(WebhookVerifier::verify($raw, 'sha256=' . $sig, (string) $ts, 'whsec_x', $ts), 'prefixed');
    foreach ([
        'bad sig' => [$raw, str_repeat('0', 64), $ts, 'whsec_x', $ts],
        'tampered body' => ['{"id":"evt_2"}', $sig, $ts, 'whsec_x', $ts],
        'stale' => [$raw, $sig, $ts, 'whsec_x', $ts + 301],
        'no secret' => [$raw, $sig, $ts, '', $ts],
        'no header' => [$raw, '', $ts, 'whsec_x', $ts],
    ] as $case => $args) {
        $threw = false;
        try {
            WebhookVerifier::verify(...$args);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        check($threw, $case . ' must be rejected');
    }
});

test('event id extraction handles payload shapes', function () {
    $ids = WebhookHandler::extractIds(['event_type' => 'checkout.completed', 'data' => ['checkout_id' => 'chk_1', 'reference' => 'WHMCS-5-abc', 'charge' => ['payment_id' => 'pay_9']]]);
    eq('chk_1', $ids['checkout_id'], 'checkout');
    eq('pay_9', $ids['payment_id'], 'nested payment');
    eq('WHMCS-5-abc', $ids['reference'], 'reference');
    $ids = WebhookHandler::extractIds(['type' => 'collection.succeeded', 'entity_type' => 'charge', 'entity_id' => 'pay_7', 'data' => ['object' => ['reference' => 'other-system']]]);
    eq('pay_7', $ids['payment_id'], 'entity id');
    eq(null, $ids['reference'], 'foreign reference ignored');
    $ids = WebhookHandler::extractIds(['event_type' => 'customer.subscription.updated', 'data' => ['id' => 'sub_3', 'status' => 'past_due']]);
    eq('sub_3', $ids['subscription_id'], 'bare id by prefix');
});

// ========================================================== one-time flow

test('one-time NGN checkout is created with invoice currency and allowed methods', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    $url = (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    check(strpos($url, 'https://checkout.bachs.io/c/') === 0, 'checkout url');
    $session = array_values($fake->sessions)[0];
    eq('15000.00', $session['amount'], 'amount');
    eq('NGN', $session['currency'], 'currency');
    eq(['card', 'crypto', 'bank_transfer'], $session['allowed'], 'methods');
    eq((string) $inv, $session['metadata']['whmcs_invoice_id'], 'metadata');

    // Reloading the invoice and clicking again reuses the open session.
    $url2 = (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    eq($url, $url2, 'reused');
    eq(1, count($fake->sessions), 'one session');
});

test('signed checkout.completed webhook marks the invoice paid exactly once', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk, null, 'crypto');

    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_1', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk]]);
    eq(200, $status, 'http');
    eq('applied', $body['status'], 'outcome');
    eq('Paid', invoiceStatus($inv), 'invoice paid');
    $rows = paymentsFor($inv);
    eq(1, count($rows), 'one transaction');
    eq($pid, $rows[0]->transid, 'transid is bachs payment id');
    eq(15000.0, (float) $rows[0]->amountin, 'amount in invoice currency');

    // Same event redelivered.
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_1', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk]]);
    eq('duplicate', $body['status'], 'dedupe by event id');
    // A different event about the same payment.
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_2', 'event_type' => 'collection.succeeded', 'entity_type' => 'charge', 'entity_id' => $pid]);
    eq(200, $status, 'http 2');
    eq(1, count(paymentsFor($inv)), 'still one transaction');
});

test('webhook with bad signature or stale timestamp is rejected', function () {
    list($cfg, $fake, $api) = setup();
    list($status) = webhook($cfg, $api, ['id' => 'evt_x', 'event_type' => 'checkout.completed'], 'whsec_wrong');
    eq(400, $status, 'wrong secret');
    list($status) = webhook($cfg, $api, ['id' => 'evt_y', 'event_type' => 'checkout.completed'], null, time() - 3600);
    eq(400, $status, 'stale');
});

test('forged webhook cannot mark an unpaid checkout as paid', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    // Correctly signed (e.g. leaked secret) but Bachs says still OPEN.
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_f', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk, 'status' => 'COMPLETED', 'amount' => '15000.00']]);
    eq('pending', $body['status'], 'API state wins');
    eq('Unpaid', invoiceStatus($inv), 'still unpaid');
});

test('amount or currency mismatch is flagged and never applied', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->sessions[$chk]['amount'] = '150.00';
    $fake->pay($chk);
    $outcome = (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    eq('mismatch', $outcome, 'mismatch');
    eq('Unpaid', invoiceStatus($inv), 'unpaid');
    eq('mismatch', Store::checkoutByCheckoutId($chk)->status, 'flagged for review');
});

test('crypto underpayment is not applied', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->pay($chk, '14000.00', 'crypto');
    $outcome = (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    eq('mismatch', $outcome, 'underpaid flagged');
    eq('Unpaid', invoiceStatus($inv), 'unpaid');
});

test('lost webhook: reconciliation cron marks the invoice paid', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->pay($chk);
    $stats = (new Reconciler($cfg, $api))->run();
    eq(1, $stats['applied'], 'applied by cron');
    eq('Paid', invoiceStatus($inv), 'paid');
    // Running again does nothing.
    $stats = (new Reconciler($cfg, $api))->run();
    eq(0, $stats['applied'], 'idempotent');
    eq(1, count(paymentsFor($inv)), 'one transaction');
});

test('concurrent webhook + return page + cron apply once', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->pay($chk);
    $row = Store::checkoutByCheckoutId($chk); // all three loaded the row before any applied
    $p = new PaymentProcessor($cfg, $api);
    $outcomes = [$p->processCheckout($row, 'webhook'), $p->processCheckout($row, 'return'), $p->processCheckout($row, 'cron')];
    eq(['applied', 'already_applied', 'already_applied'], $outcomes, 'outcomes');
    eq(1, count(paymentsFor($inv)), 'one transaction');
});

test('payment claim is exclusive and only re-claimable from error/unmatched', function () {
    setup();
    $data = ['kind' => 'checkout', 'invoice_id' => 1, 'client_id' => 1, 'amount' => '1.00', 'currency' => 'USD'];
    check(Store::claimPayment('pay_x', $data), 'first claim');
    check(!Store::claimPayment('pay_x', $data), 'second claim refused while processing');
    Store::updatePayment('pay_x', ['status' => 'applied']);
    check(!Store::claimPayment('pay_x', $data), 'applied is final');
    Store::updatePayment('pay_x', ['status' => 'error']);
    check(Store::claimPayment('pay_x', $data), 're-claim after error');
    check(!Store::claimPayment('pay_x', $data), 'and exclusive again');
    Capsule::table(Schema::T_PAYMENTS)->where('payment_id', 'pay_x')->update(['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s', time() - 3600)]);
    check(Store::claimPayment('pay_x', $data), 'stale processing (crashed worker) re-claimable');
});

test('WHMCS failure while applying is retried by the cron', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    // Simulate a crash mid-apply: claim taken, then error recorded.
    Store::claimPayment($pid, ['kind' => 'checkout', 'invoice_id' => $inv, 'client_id' => 1, 'amount' => '15000.00', 'currency' => 'NGN']);
    Store::updatePayment($pid, ['status' => 'error', 'note' => 'simulated']);
    $stats = (new Reconciler($cfg, $api))->run();
    eq('Paid', invoiceStatus($inv), 'recovered');
    eq('applied', Store::paymentById($pid)->status, 'applied');
    eq(1, count(paymentsFor($inv)), 'one transaction');
});

test('payment for an invoice already paid elsewhere becomes credit', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->pay($chk);
    Capsule::table('tblinvoices')->where('id', $inv)->update(['status' => 'Paid']);
    $outcome = (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    eq('credited', $outcome, 'credited');
    eq(15000.0, (float) Capsule::table('tblclients')->where('id', 1)->value('credit'), 'credit balance');
});

test('expired checkout is closed', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->sessions[$chk]['status'] = 'EXPIRED';
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_e', 'event_type' => 'checkout.expired', 'data' => ['checkout_id' => $chk]]);
    eq('expired', $body['status'], 'expired');
    eq('expired', Store::checkoutByCheckoutId($chk)->status, 'row');
});

test('convert-to-for-processing charges in the converted currency', function () {
    list($cfg, $fake, $api) = setup(['convertto' => '1']);
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    eq('10.00', $fake->sessions[$chk]['amount'], 'USD amount');
    eq('USD', $fake->sessions[$chk]['currency'], 'USD');
    $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    eq('Paid', invoiceStatus($inv), 'paid');
    eq(15000.0, (float) paymentsFor($inv)[0]->amountin, 'applied in NGN');
});

test('API outage returns 500 so Bachs retries the webhook', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $fake->pay($chk);
    $fake->failNext = [503, ['detail' => 'down']];
    // ApiClient retries twice; make all attempts fail.
    $calls = 0;
    $flaky = function ($m, $u, $h, $p) use ($fake, &$calls) {
        $calls++;
        return $calls <= 3 ? [503, [], '{"detail":"down"}'] : $fake($m, $u, $h, $p);
    };
    $api2 = new ApiClient($cfg->secretKey(), $cfg->baseUrl(), $flaky);
    list($status) = webhook($cfg, $api2, ['id' => 'evt_o', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk]]);
    eq(500, $status, 'retry requested');
    list($status, $body) = webhook($cfg, $api2, ['id' => 'evt_o', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk]]);
    eq('applied', $body['status'], 'redelivery processed');
    eq('Paid', invoiceStatus($inv), 'paid');
});

// ============================================================== auto-pay

test('auto-pay is offered only for clean recurring invoices', function () {
    list($cfg, $fake, $api) = setup();
    $svc = new CheckoutService($cfg, $api);
    list($hid, $inv) = usdHosting(10.00);
    $q = $svc->quoteSubscription(\HarmonWeb\Bachs\Whmcs::invoice($inv));
    check($q['eligible'], 'clean renewal eligible: ' . ($q['reason'] ?? ''));
    eq('10.00', $q['amount'], 'amount');
    eq(['interval' => 'month', 'frequency' => 1], $q['cadence'], 'cadence');

    // Setup fee on first invoice.
    $hid2 = Capsule::table('tblhosting')->insertGetId(['userid' => 2, 'packageid' => 1, 'billingcycle' => 'Monthly', 'amount' => 10]);
    $inv2 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid2, 'amount' => 25]]);
    check(!$svc->quoteSubscription(\HarmonWeb\Bachs\Whmcs::invoice($inv2))['eligible'], 'setup fee not eligible');

    // Mixed cycles.
    $hid3 = Capsule::table('tblhosting')->insertGetId(['userid' => 2, 'packageid' => 1, 'billingcycle' => 'Annually', 'amount' => 100]);
    $inv3 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 10], ['type' => 'Hosting', 'relid' => $hid3, 'amount' => 100]]);
    check(!$svc->quoteSubscription(\HarmonWeb\Bachs\Whmcs::invoice($inv3))['eligible'], 'mixed cycles');

    // NGN client without conversion enabled.
    $hid4 = Capsule::table('tblhosting')->insertGetId(['userid' => 1, 'packageid' => 1, 'billingcycle' => 'Monthly', 'amount' => 15000]);
    $inv4 = bachs_test_invoice(1, [['type' => 'Hosting', 'relid' => $hid4, 'amount' => 15000]]);
    check(!$svc->quoteSubscription(\HarmonWeb\Bachs\Whmcs::invoice($inv4))['eligible'], 'NGN needs conversion');
});

test('auto-pay signup, linking, renewal and cancellation', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);

    $url = (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    eq(['card'], $fake->sessions[$chk]['allowed'], 'card only for subscriptions');
    check(!empty($fake->sessions[$chk]['product_cart']), 'uses recurring product');
    eq(1, count($fake->customers), 'bachs customer created');
    $prod = array_values($fake->products)[0];
    eq(['interval' => 'month', 'frequency' => 1], $prod['billing_cycle'], 'product cadence');

    $pid = $fake->pay($chk);
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_s1', 'event_type' => 'checkout.completed', 'data' => ['checkout_id' => $chk]]);
    eq('applied', $body['status'], 'first payment applied');
    eq('Paid', invoiceStatus($inv), 'paid');

    $subId = $fake->payments[$pid]['subscription_id'];
    $local = Store::subscription($subId);
    check($local !== null, 'subscription mapped');
    eq('active', $local->status, 'active');
    eq($subId, Capsule::table('tblhosting')->where('id', $hid)->value('subscriptionid'), 'WHMCS subscriptionid set');

    // Next month: WHMCS generates the renewal invoice, Bachs charges the card.
    $inv2 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 10.00]]);
    $renewal = $fake->renew($subId);
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_s2', 'event_type' => 'collection.succeeded', 'data' => ['payment_id' => $renewal, 'subscription_id' => $subId]]);
    eq('applied', $body['status'], 'renewal applied');
    eq('Paid', invoiceStatus($inv2), 'renewal invoice paid');
    eq($renewal, paymentsFor($inv2)[0]->transid, 'renewal transid');
    eq(0.30, (float) paymentsFor($inv2)[0]->fees, 'USD fee recorded');

    // Pay-button page now says auto-pay is active.
    $inv3 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 10.00]]);
    $html = bachs_link($GLOBALS['__gateway'] + ['invoiceid' => $inv3]);
    check(strpos($html, 'Auto-pay is active') !== false, 'notice shown');

    // Admin cancels the subscription from WHMCS.
    $res = bachs_cancelSubscription($GLOBALS['__gateway'] + ['subscriptionID' => $subId]);
    eq('success', $res['status'], 'cancel ok');
    eq('canceled', $fake->subscriptions[$subId]['status'], 'cancelled at Bachs');
    eq('', Capsule::table('tblhosting')->where('id', $hid)->value('subscriptionid'), 'WHMCS subscriptionid cleared');
});

test('renewal charged before the invoice exists is parked, then applied by cron', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    $subId = $fake->payments[$pid]['subscription_id'];

    $renewal = $fake->renew($subId);
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_r', 'event_type' => 'collection.succeeded', 'entity_type' => 'payment', 'entity_id' => $renewal]);
    eq('unmatched', $body['status'], 'parked');
    eq('unmatched', Store::paymentById($renewal)->status, 'row');

    $inv2 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 10.00]]);
    (new Reconciler($cfg, $api))->run();
    eq('Paid', invoiceStatus($inv2), 'applied once invoice exists');
    eq('applied', Store::paymentById($renewal)->status, 'applied');
});

test('unmatched renewal is credited after the grace period', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    $subId = $fake->payments[$pid]['subscription_id'];
    $renewal = $fake->renew($subId);
    (new PaymentProcessor($cfg, $api))->processPaymentId($renewal, 'test');
    Capsule::table(Schema::T_PAYMENTS)->where('payment_id', $renewal)->update(['created_at' => date('Y-m-d H:i:s', time() - 4 * 86400)]);
    (new Reconciler($cfg, $api))->run();
    eq('credited', Store::paymentById($renewal)->status, 'credited');
    eq(10.0, (float) Capsule::table('tblclients')->where('id', 2)->value('credit'), 'credit added');
});

test('missed renewal webhook is caught by the payment sweep', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    $subId = $fake->payments[$pid]['subscription_id'];

    $inv2 = bachs_test_invoice(2, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 10.00]]);
    $fake->renew($subId); // no webhook delivered
    $stats = (new Reconciler($cfg, $api))->run();
    eq(1, $stats['renewals_applied'], 'sweep applied');
    eq('Paid', invoiceStatus($inv2), 'paid');
});

test('NGN client auto-pay via USD conversion settles the NGN invoice', function () {
    list($cfg, $fake, $api) = setup(['autopayConvert' => 'on']);
    $hid = Capsule::table('tblhosting')->insertGetId(['userid' => 1, 'packageid' => 1, 'billingcycle' => 'Monthly', 'amount' => 15000]);
    $inv = bachs_test_invoice(1, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    eq('10.00', $fake->sessions[$chk]['amount'], 'USD 10');
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    eq('Paid', invoiceStatus($inv), 'first paid');
    eq(15000.0, (float) paymentsFor($inv)[0]->amountin, 'NGN amount');

    $subId = $fake->payments[$pid]['subscription_id'];
    $inv2 = bachs_test_invoice(1, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 15000]]);
    $renewal = $fake->renew($subId);
    (new PaymentProcessor($cfg, $api))->processPaymentId($renewal, 'test');
    eq('Paid', invoiceStatus($inv2), 'renewal settles NGN invoice');
    eq(0.0, (float) paymentsFor($inv2)[0]->fees, 'no USD fee on NGN invoice');
});

test('price change reprices the subscription; terminated service cancels it', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    $subId = $fake->payments[$pid]['subscription_id'];

    Capsule::table('tblhosting')->where('id', $hid)->update(['amount' => 12.50]);
    $svc = new SubscriptionService($cfg, $api);
    eq('repriced', $svc->maintain(Store::subscription($subId)), 'repriced');
    eq('12.50', $fake->subscriptions[$subId]['amount'], 'bachs amount updated');
    eq('12.50', Store::subscription($subId)->amount, 'local amount');

    Capsule::table('tblhosting')->where('id', $hid)->update(['domainstatus' => 'Terminated']);
    eq('cancelled', $svc->maintain(Store::subscription($subId)), 'cancelled');
    eq('canceled', $fake->subscriptions[$subId]['status'], 'cancelled at Bachs');
});

test('subscription.deleted webhook syncs status and detaches the service', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    $subId = $fake->payments[$pid]['subscription_id'];
    $fake->subscriptions[$subId]['status'] = 'canceled';
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_d', 'event_type' => 'customer.subscription.deleted', 'data' => ['id' => $subId]]);
    eq('synced', $body['status'], 'synced');
    eq('canceled', Store::subscription($subId)->status, 'local status');
    eq('', Capsule::table('tblhosting')->where('id', $hid)->value('subscriptionid'), 'detached');
});

test('subscription id arriving only via subscription.created is adopted', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    (new CheckoutService($cfg, $api))->start($inv, 'subscription');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    $subId = $fake->payments[$pid]['subscription_id'];
    // Charge on the session does not carry the subscription id.
    $fake->sessions[$chk]['charge']['subscription_id'] = null;
    $fake->subscriptions[$subId]['product'] = ['id' => 'prod_other']; // also defeat the list fallback
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');
    check(Store::subscription($subId) === null, 'not linked yet');
    $fake->subscriptions[$subId]['product'] = ['id' => array_keys($fake->products)[0]];
    list($status, $body) = webhook($cfg, $api, ['id' => 'evt_c', 'event_type' => 'customer.subscription.created', 'data' => ['id' => $subId]]);
    eq('linked', $body['status'], 'adopted');
    eq($subId, Capsule::table('tblhosting')->where('id', $hid)->value('subscriptionid'), 'linked to service');
});

// ======================================================= gateway functions

test('pay button renders signed forms and both options', function () {
    list($cfg, $fake, $api) = setup();
    list($hid, $inv) = usdHosting(10.00);
    $html = bachs_link($GLOBALS['__gateway'] + ['invoiceid' => $inv]);
    check(strpos($html, 'modules/gateways/bachs/pay.php') !== false, 'posts to pay.php');
    check(strpos($html, CheckoutService::formToken($cfg, $inv, 'one_time')) !== false, 'one-time token');
    check(strpos($html, 'enable auto-renew') !== false, 'auto-pay offered');
    check(CheckoutService::verifyFormToken($cfg, $inv, 'one_time', CheckoutService::formToken($cfg, $inv, 'one_time')), 'token verifies');
    check(!CheckoutService::verifyFormToken($cfg, $inv + 1, 'one_time', CheckoutService::formToken($cfg, $inv, 'one_time')), 'token bound to invoice');
    eq(0, count($fake->requests), 'rendering makes no API calls');

    Capsule::table('tblinvoices')->where('id', $inv)->update(['status' => 'Paid']);
    eq('', bachs_link($GLOBALS['__gateway'] + ['invoiceid' => $inv]), 'no button on paid invoice');
});

test('ineligible auto-pay explains why to admins only', function () {
    setup();
    $hid = Capsule::table('tblhosting')->insertGetId(['userid' => 1, 'packageid' => 1, 'billingcycle' => 'Monthly', 'amount' => 15000]);
    $inv = bachs_test_invoice(1, [['type' => 'Hosting', 'relid' => $hid, 'amount' => 15000]]);
    $html = bachs_link($GLOBALS['__gateway'] + ['invoiceid' => $inv]);
    check(strpos($html, 'auto-renew') === false, 'client sees nothing');
    $_SESSION['adminid'] = 1;
    $html = bachs_link($GLOBALS['__gateway'] + ['invoiceid' => $inv]);
    unset($_SESSION['adminid']);
    check(strpos($html, 'USD only') !== false, 'admin sees reason: ' . strip_tags($html));
});

test('refunds: full and partial', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $chk = array_keys($fake->sessions)[0];
    $pid = $fake->pay($chk);
    (new PaymentProcessor($cfg, $api))->processCheckout(Store::checkoutByCheckoutId($chk), 'test');

    $res = bachs_refund($GLOBALS['__gateway'] + ['transid' => $pid, 'invoiceid' => $inv, 'amount' => 15000, 'currency' => 'NGN']);
    eq('success', $res['status'], 'full refund');
    $last = end($fake->refunds);
    check(!isset($last['amount']), 'full refund omits amount');

    $res = bachs_refund($GLOBALS['__gateway'] + ['transid' => $pid, 'invoiceid' => $inv, 'amount' => 5000, 'currency' => 'NGN']);
    eq('success', $res['status'], 'partial refund');
    $last = end($fake->refunds);
    eq('5000.00', $last['amount'], 'partial amount');
});

test('config: live mode rejects sandbox keys and vice versa', function () {
    setup();
    $threw = false;
    try {
        bachs_test_config(['liveSecretKey' => 'sk_sandbox_oops'])->validateKey();
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'sandbox key in live mode');
    $threw = false;
    try {
        bachs_test_config(['testMode' => 'on', 'sandboxSecretKey' => 'sk_live_oops'])->validateKey();
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'live key in sandbox mode');
    eq('https://sandbox-api.bachs.io', bachs_test_config(['testMode' => 'on'])->baseUrl(), 'sandbox url');
});

test('secrets never reach the logs', function () {
    list($cfg, $fake, $api) = setup();
    $inv = bachs_test_invoice(1, [['type' => 'Item', 'amount' => 15000]]);
    (new CheckoutService($cfg, $api))->start($inv, 'one_time');
    $c = new CheckoutService($cfg, $api);
    $c->ensureCustomer(1);
    $c->portalUrl(1);
    $dump = json_encode($GLOBALS['__log']);
    check(strpos($dump, 'sk_live_test_123456789') === false, 'api key masked');
    check(strpos($dump, 'portal.bachs.io/s/secret') === false, 'portal url masked');
});

// ================================================================== runner

$passed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "  \033[31m✗ $name\033[0m\n    " . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n$passed passed, $failures failed\n";
exit($failures ? 1 : 0);
