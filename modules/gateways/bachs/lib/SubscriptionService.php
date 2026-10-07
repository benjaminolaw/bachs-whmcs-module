<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Keeps Bachs subscriptions (saved-card auto-pay) in step with WHMCS services.
 */
class SubscriptionService
{
    const ACTIVE_STATUSES = ['active', 'trialing', 'past_due', 'pending'];
    const INACTIVE_ITEM_STATUSES = ['Cancelled', 'Terminated', 'Fraud', 'Expired', 'Transferred Away'];

    /** @var Config */
    private $config;

    /** @var ApiClient */
    private $api;

    public function __construct(Config $config, ApiClient $api)
    {
        $this->config = $config;
        $this->api = $api;
    }

    /**
     * Record the subscription created by a completed auto-pay checkout and
     * attach it to the WHMCS services it pays for.
     */
    public function linkFromCheckout($row, $subscriptionId, array $session = [])
    {
        $plan = json_decode((string) $row->subscription_plan, true);
        if (!$plan) {
            return;
        }

        $customerId = $session['customer']['id'] ?? Store::customerId($row->client_id, (bool) $row->sandbox);

        if (!$subscriptionId && $customerId) {
            $subscriptionId = $this->findSubscriptionForCheckout($customerId, $plan, $row);
        }
        if (!$subscriptionId) {
            // The payment is applied; the subscription ID will arrive with
            // customer.subscription.created or the next cron pass.
            Logger::activity('Auto-pay checkout ' . $row->checkout_id . ' completed; waiting for subscription id', $row->client_id);
            return;
        }

        $remote = [];
        try {
            $remote = $this->api->getSubscription($subscriptionId);
        } catch (ApiException $e) {
            Logger::debug('getSubscription', $e->getMessage());
        }

        // Never leave a client with two active auto-pays for the same service.
        foreach ($plan['items'] as $item) {
            $old = Store::subscriptionForItem($item['type'], $item['relid']);
            if ($old && $old->subscription_id !== $subscriptionId) {
                try {
                    $this->cancel($old->subscription_id, 'Replaced by ' . $subscriptionId);
                } catch (\Exception $e) {
                    Logger::activity('Could not cancel replaced subscription ' . $old->subscription_id . ': ' . $e->getMessage(), $row->client_id);
                }
            }
        }

        Store::saveSubscription($subscriptionId, [
            'client_id' => $row->client_id,
            'customer_id' => $customerId,
            'product_id' => $plan['product_id'],
            'items' => json_encode(array_map(function ($i) {
                return ['type' => $i['type'], 'relid' => (int) $i['relid']];
            }, $plan['items'])),
            'amount' => $plan['amount'],
            'currency' => $plan['currency'],
            'interval' => $plan['interval'],
            'frequency' => (int) $plan['frequency'],
            'invoice_amount' => $plan['invoice_amount'],
            'invoice_currency' => $plan['invoice_currency'],
            'tax_ratio' => $plan['tax_ratio'],
            'status' => strtolower((string) ($remote['status'] ?? 'active')),
            'next_billed_at' => self::date($remote['next_billed_at'] ?? ($remote['current_period_end'] ?? null)),
            'origin_invoice_id' => $row->invoice_id,
            'sandbox' => $row->sandbox,
            'last_synced_at' => Store::now(),
        ]);

        foreach ($plan['items'] as $item) {
            Whmcs::setSubscriptionId($item['type'], $item['relid'], $subscriptionId);
        }
        if ((string) $row->subscription_id !== (string) $subscriptionId) {
            Store::updateCheckout($row->id, ['subscription_id' => $subscriptionId]);
        }
        Logger::activity('Auto-pay subscription ' . $subscriptionId . ' linked from invoice #' . $row->invoice_id, $row->client_id);
    }

    /**
     * A subscription we have not linked yet (e.g. customer.subscription.created
     * arrived before/without the payment carrying its ID): attach it to the
     * customer's most recent completed auto-pay checkout that lacks one.
     */
    public function adoptOrphan($subscriptionId)
    {
        if (Store::subscription($subscriptionId)) {
            return false;
        }
        $remote = $this->api->getSubscription($subscriptionId);
        $customerId = $remote['customer']['customer_id'] ?? null;
        if (!$customerId) {
            return false;
        }
        $clientId = Capsule::table(Schema::T_CUSTOMERS)
            ->where('customer_id', $customerId)
            ->where('sandbox', $this->config->isSandbox() ? 1 : 0)
            ->value('client_id');
        if (!$clientId) {
            return false;
        }
        $productId = $remote['product']['id'] ?? ($remote['items'][0]['product']['id'] ?? null);
        $rows = Capsule::table(Schema::T_CHECKOUTS)
            ->where('client_id', $clientId)
            ->where('mode', CheckoutService::MODE_SUBSCRIPTION)
            ->where('status', 'completed')
            ->whereNull('subscription_id')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();
        foreach ($rows as $row) {
            $plan = json_decode((string) $row->subscription_plan, true);
            if ($plan && (!$productId || $plan['product_id'] === $productId)) {
                $this->linkFromCheckout($row, $subscriptionId, ['customer' => ['id' => $customerId]]);
                return true;
            }
        }
        return false;
    }

    /**
     * Fallback when the checkout response did not include the subscription:
     * the newest subscription of this customer with the same product.
     */
    private function findSubscriptionForCheckout($customerId, array $plan, $row)
    {
        try {
            $resp = $this->api->request('GET', '/v1/subscriptions', null, null, ['customer_id' => $customerId, 'limit' => 20]);
        } catch (ApiException $e) {
            return null;
        }
        $created = strtotime($row->created_at) - 300;
        foreach ((array) ($resp['items'] ?? []) as $s) {
            $productId = $s['product']['id'] ?? ($s['items'][0]['product']['id'] ?? null);
            if ($productId !== $plan['product_id'] || empty($s['id'])) {
                continue;
            }
            if (Store::subscription($s['id'])) {
                continue; // already linked to another checkout
            }
            if (!empty($s['created_at']) && strtotime($s['created_at']) < $created) {
                continue;
            }
            return $s['id'];
        }
        return null;
    }

    /**
     * Pull the latest state from Bachs. Unknown subscriptions are ignored.
     */
    public function sync($subscriptionId, ?array $remote = null)
    {
        $local = Store::subscription($subscriptionId);
        if (!$local) {
            return null;
        }
        if ($remote === null) {
            try {
                $remote = $this->api->getSubscription($subscriptionId);
            } catch (ApiException $e) {
                if ($e->isNotFound()) {
                    $remote = ['status' => 'canceled'];
                } else {
                    throw $e;
                }
            }
        }
        $status = strtolower((string) ($remote['status'] ?? $local->status));
        $update = ['status' => $status, 'last_synced_at' => Store::now()];
        if (!empty($remote['next_billed_at']) || !empty($remote['current_period_end'])) {
            $update['next_billed_at'] = self::date($remote['next_billed_at'] ?? $remote['current_period_end']);
        }
        if (!empty($remote['amount'])) {
            $update['amount'] = (string) $remote['amount'];
        }
        Store::saveSubscription($subscriptionId, $update);

        if ($status !== $local->status) {
            Logger::activity('Subscription ' . $subscriptionId . ' status ' . $local->status . ' -> ' . $status, $local->client_id);
        }
        if (in_array($status, ['canceled', 'cancelled'], true)) {
            Whmcs::clearSubscriptionId($subscriptionId);
        }
        return $status;
    }

    /**
     * Cancel immediately at Bachs and detach from WHMCS services.
     */
    public function cancel($subscriptionId, $reason = 'Cancelled from WHMCS')
    {
        try {
            $this->api->cancelSubscription($subscriptionId, false, $reason, 'cancel-' . $subscriptionId);
        } catch (ApiException $e) {
            // Already cancelled/unknown at Bachs is fine; anything else bubbles up.
            if (!$e->isNotFound() && $e->getHttpStatus() !== 409) {
                throw $e;
            }
        }
        if (Store::subscription($subscriptionId)) {
            Store::saveSubscription($subscriptionId, ['status' => 'canceled', 'last_synced_at' => Store::now()]);
        }
        Whmcs::clearSubscriptionId($subscriptionId);
        Logger::activity('Auto-pay subscription ' . $subscriptionId . ' cancelled: ' . $reason);
    }

    /**
     * Cancel when every service it pays for is gone, and move it to the new
     * price when the WHMCS recurring amount changed (upgrade, price change,
     * exchange-rate drift for converted subscriptions).
     */
    public function maintain($local)
    {
        $items = json_decode($local->items, true) ?: [];
        $inactive = 0;
        foreach ($items as $item) {
            $status = Whmcs::itemStatus($item['type'], $item['relid']);
            if ($status === null || in_array($status, self::INACTIVE_ITEM_STATUSES, true)) {
                $inactive++;
            }
        }
        if ($items && $inactive === count($items)) {
            $this->cancel($local->subscription_id, 'All linked services are no longer active');
            return 'cancelled';
        }

        if (!$this->config->priceSyncEnabled()) {
            return 'ok';
        }
        $expected = $this->expectedAmount($local, $items);
        if ($expected === null || $expected < 1) {
            return 'ok';
        }
        if (Money::relativeDiff($expected, $local->amount) <= $this->config->priceSyncThreshold()) {
            return 'ok';
        }

        $checkout = new CheckoutService($this->config, $this->api);
        $productId = $checkout->ensureProduct($expected, ['interval' => $local->interval, 'frequency' => (int) $local->frequency]);
        $this->api->updateSubscription($local->subscription_id, [
            'product_id' => $productId,
            'proration_behavior' => 'none',
        ], 'reprice-' . $local->subscription_id . '-' . $productId);
        Store::saveSubscription($local->subscription_id, [
            'product_id' => $productId,
            'amount' => Money::format($expected, $local->currency),
        ]);
        Logger::activity('Subscription ' . $local->subscription_id . ' repriced ' . $local->amount . ' -> ' . Money::format($expected, $local->currency) . ' ' . $local->currency, $local->client_id);
        return 'repriced';
    }

    /** Current recurring total of the linked services, tax-adjusted, in the subscription currency. */
    private function expectedAmount($local, array $items)
    {
        $sum = 0.0;
        foreach ($items as $item) {
            switch ($item['type']) {
                case 'Hosting':
                    $sum += (float) Capsule::table('tblhosting')->where('id', $item['relid'])->value('amount');
                    break;
                case 'Addon':
                    $sum += (float) Capsule::table('tblhostingaddons')->where('id', $item['relid'])->value('recurring');
                    break;
                case 'Domain':
                    $sum += (float) Capsule::table('tbldomains')->where('id', $item['relid'])->value('recurringamount');
                    break;
            }
        }
        $sum = round($sum * (float) $local->tax_ratio, 2);
        return Whmcs::convert($sum, $local->invoice_currency, $local->currency);
    }

    private static function date($iso)
    {
        if (!$iso) {
            return null;
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
