<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Payment validation cron.
 *
 * Catches every case where money reached Bachs but the WHMCS invoice was not
 * marked paid: lost/late webhooks, customer closed the browser before the
 * return page, WHMCS errors while applying, renewals charged before the
 * invoice existed. Runs from the WHMCS cron (AfterCronJob hook) and/or the
 * standalone CLI script. A MySQL advisory lock prevents overlapping runs.
 */
class Reconciler
{
    const MAX_CHECKOUTS_PER_RUN = 60;
    const MAX_PAYMENT_LOOKUPS_PER_RUN = 25;
    const MAX_SWEEP_PAGES = 5;

    /** @var Config */
    private $config;

    /** @var ApiClient */
    private $api;

    /** @var PaymentProcessor */
    private $processor;

    /** @var SubscriptionService */
    private $subscriptions;

    /** @var array */
    private $stats = [];

    /** @var callable|null */
    private $out;

    /** @var bool set false to force the payment sweep (CLI / tests) */
    public $throttleSweep = true;

    public function __construct(Config $config, ApiClient $api, ?callable $out = null)
    {
        $this->config = $config;
        $this->api = $api;
        $this->processor = new PaymentProcessor($config, $api);
        $this->subscriptions = new SubscriptionService($config, $api);
        $this->out = $out;
    }

    /**
     * @return array stats, or ['skipped' => 'locked'] when another run is active
     */
    public function run()
    {
        if (!Store::lock('reconcile')) {
            return ['skipped' => 'locked'];
        }
        $this->stats = [
            'checkouts_checked' => 0,
            'applied' => 0,
            'expired' => 0,
            'renewals_applied' => 0,
            'credited' => 0,
            'swept' => 0,
            'subscriptions_synced' => 0,
            'errors' => 0,
        ];
        $started = microtime(true);
        try {
            $this->step('pendingCheckouts');
            $this->step('appliedAudit');
            $this->step('unmatchedRenewals');
            $this->step('sweepRecentPayments');
            $this->step('unlinkedSubscriptions');
            $this->step('subscriptions');
        } finally {
            Store::unlock('reconcile');
        }
        $this->stats['seconds'] = round(microtime(true) - $started, 2);
        Store::kvSet('last_reconcile', json_encode(['at' => Store::now()] + $this->stats));
        if ($this->stats['applied'] || $this->stats['renewals_applied'] || $this->stats['credited'] || $this->stats['errors']) {
            Logger::activity('Reconciliation: ' . json_encode($this->stats));
        }
        return $this->stats;
    }

    private function step($method)
    {
        try {
            $this->$method();
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->say('! ' . $method . ': ' . $e->getMessage());
            Logger::activity('Reconciliation step ' . $method . ' failed: ' . $e->getMessage());
        }
    }

    private function say($line)
    {
        if ($this->out) {
            call_user_func($this->out, $line);
        }
    }

    /**
     * 1. Re-check checkouts that are not finished yet, backing off as they age:
     *    every run for the first hour, every 30 min for a day, then every 3 hours.
     */
    private function pendingCheckouts()
    {
        $since = date('Y-m-d H:i:s', time() - $this->config->reconcileLookbackDays() * 86400);
        $rows = Capsule::table(Schema::T_CHECKOUTS)
            ->whereIn('status', ['open', 'underpaid', 'error', 'failed'])
            ->whereNotNull('checkout_id')
            ->where('created_at', '>=', $since)
            ->where('sandbox', $this->config->isSandbox() ? 1 : 0)
            ->orderBy('last_checked_at', 'asc')
            ->limit(self::MAX_CHECKOUTS_PER_RUN * 3)
            ->get();

        $checked = 0;
        foreach ($rows as $row) {
            if ($checked >= self::MAX_CHECKOUTS_PER_RUN) {
                break;
            }
            if (!$this->due($row)) {
                continue;
            }
            $checked++;
            $this->stats['checkouts_checked']++;
            try {
                $outcome = $this->processor->processCheckout($row, 'cron');
                $this->count($outcome);
                $this->say(sprintf('checkout %s (invoice #%d): %s', $row->checkout_id, $row->invoice_id, $outcome));
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->say(sprintf('checkout %s: error %s', $row->checkout_id, $e->getMessage()));
            }
        }
    }

    private function due($row)
    {
        if (!$row->last_checked_at) {
            return true;
        }
        $age = time() - strtotime($row->created_at);
        $since = time() - strtotime($row->last_checked_at);
        if ($age < 3600) {
            return true;
        }
        if ($age < 86400) {
            return $since >= 1800;
        }
        return $since >= 10800;
    }

    /**
     * 2. Payments we recorded as applied/processing but which have no WHMCS
     *    transaction (crash mid-apply, manual deletion): re-apply them.
     */
    private function appliedAudit()
    {
        $since = date('Y-m-d H:i:s', time() - $this->config->reconcileLookbackDays() * 86400);
        $stale = date('Y-m-d H:i:s', time() - 600);
        $rows = Capsule::table(Schema::T_PAYMENTS)
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($stale) {
                $q->where('status', 'error')
                    ->orWhere(function ($q2) use ($stale) {
                        $q2->where('status', 'processing')->where('updated_at', '<', $stale);
                    });
            })
            ->where('attempts', '<', 20)
            ->limit(self::MAX_PAYMENT_LOOKUPS_PER_RUN)
            ->get();

        foreach ($rows as $row) {
            if (Whmcs::transactionExists($row->payment_id)) {
                Store::updatePayment($row->payment_id, ['status' => 'applied', 'note' => 'Found existing transaction']);
                continue;
            }
            try {
                if ($row->kind === 'checkout') {
                    $checkout = Capsule::table(Schema::T_CHECKOUTS)
                        ->where('payment_id', $row->payment_id)
                        ->orWhere('checkout_id', $row->payment_id)
                        ->first();
                    if (!$checkout && $row->invoice_id) {
                        $checkout = Capsule::table(Schema::T_CHECKOUTS)->where('invoice_id', $row->invoice_id)
                            ->whereNotIn('status', ['expired', 'cancelled'])->orderBy('id', 'desc')->first();
                    }
                    $outcome = $checkout ? $this->processor->processCheckout($checkout, 'cron-audit') : 'missing';
                } else {
                    $outcome = $this->processor->processPaymentId($row->payment_id, 'cron-audit');
                }
                $this->count($outcome, $row->kind === 'renewal');
                $this->say('retry payment ' . $row->payment_id . ': ' . $outcome);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->say('retry payment ' . $row->payment_id . ': error ' . $e->getMessage());
            }
        }
    }

    /** 3. Renewal charges waiting for their WHMCS invoice. */
    private function unmatchedRenewals()
    {
        $rows = Capsule::table(Schema::T_PAYMENTS)->where('status', 'unmatched')->limit(100)->get();
        foreach ($rows as $row) {
            try {
                $outcome = $this->processor->retryUnmatched($row);
                $this->count($outcome, true);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->say('unmatched ' . $row->payment_id . ': error ' . $e->getMessage());
            }
        }
    }

    /**
     * 4. Walk recent successful payments at Bachs and make sure every one that
     *    belongs to us is reflected in WHMCS. This catches renewals whose
     *    webhook never arrived and checkouts whose local row was never updated.
     */
    private function sweepRecentPayments()
    {
        // Throttled to every 15 minutes: the WHMCS cron may fire every 5.
        if ($this->throttleSweep && Store::kvGet('last_sweep', 900) !== null) {
            return;
        }
        Store::kvSet('last_sweep', Store::now());

        $cutoff = time() - $this->config->reconcileLookbackDays() * 86400;
        $lookups = 0;
        $offset = 0;
        for ($page = 0; $page < self::MAX_SWEEP_PAGES; $page++) {
            $resp = $this->api->listPayments(100, $offset);
            $items = isset($resp['items']) && is_array($resp['items']) ? $resp['items'] : [];
            if (!$items) {
                break;
            }
            $reachedCutoff = false;
            foreach ($items as $p) {
                $when = strtotime($p['transaction_date'] ?? ($p['completed_at'] ?? '')) ?: time();
                if ($when < $cutoff) {
                    $reachedCutoff = true;
                    continue;
                }
                $id = (string) ($p['id'] ?? '');
                if ($id === '' || !PaymentProcessor::isSuccess($p['status'] ?? '')) {
                    continue;
                }
                $known = Store::paymentById($id);
                if ($known && in_array($known->status, ['applied', 'credited', 'duplicate', 'unmatched'], true)) {
                    continue;
                }
                $this->stats['swept']++;

                try {
                    $reference = (string) ($p['reference'] ?? '');
                    if (strpos($reference, 'WHMCS-') === 0) {
                        $row = Store::checkoutByReference($reference);
                        if ($row && $row->status !== 'completed') {
                            $outcome = $this->processor->processCheckout($row, 'cron-sweep');
                            $this->count($outcome);
                            $this->say('sweep ' . $id . ' (' . $reference . '): ' . $outcome);
                        }
                        continue;
                    }
                    // Not one of our checkout references: could be a subscription
                    // renewal. Look it up (bounded per run).
                    if ($lookups >= self::MAX_PAYMENT_LOOKUPS_PER_RUN) {
                        continue;
                    }
                    if (!$this->mightBeOurs($p)) {
                        continue;
                    }
                    $lookups++;
                    $outcome = $this->processor->processPaymentId($id, 'cron-sweep');
                    $this->count($outcome, true);
                    if ($outcome !== 'not_ours') {
                        $this->say('sweep ' . $id . ': ' . $outcome);
                    }
                } catch (\Exception $e) {
                    $this->stats['errors']++;
                    $this->say('sweep ' . $id . ': error ' . $e->getMessage());
                }
            }
            $pagination = $resp['pagination'] ?? [];
            if ($reachedCutoff || empty($pagination['has_more'])) {
                break;
            }
            $offset += (int) ($pagination['returned'] ?? count($items));
        }
    }

    /** Cheap pre-filter: payer email belongs to a WHMCS client with a Bachs subscription. */
    private function mightBeOurs(array $p)
    {
        $email = strtolower(trim((string) ($p['customer_email'] ?? '')));
        if ($email === '') {
            return true;
        }
        $clientId = Capsule::table('tblclients')->where('email', $email)->value('id');
        if (!$clientId) {
            return false;
        }
        return Capsule::table(Schema::T_SUBSCRIPTIONS)->where('client_id', $clientId)->exists();
    }

    /** 5. Auto-pay checkouts paid but whose subscription ID we never received. */
    private function unlinkedSubscriptions()
    {
        $rows = Capsule::table(Schema::T_CHECKOUTS)
            ->where('mode', CheckoutService::MODE_SUBSCRIPTION)
            ->where('status', 'completed')
            ->whereNull('subscription_id')
            ->where('sandbox', $this->config->isSandbox() ? 1 : 0)
            ->where('updated_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))
            ->limit(20)
            ->get();
        foreach ($rows as $row) {
            $this->subscriptions->linkFromCheckout($row, null);
        }
    }

    /** 6. Sync subscription status twice a day; cancel/reprice when services change. */
    private function subscriptions()
    {
        $rows = Capsule::table(Schema::T_SUBSCRIPTIONS)
            ->whereIn('status', SubscriptionService::ACTIVE_STATUSES)
            ->where('sandbox', $this->config->isSandbox() ? 1 : 0)
            ->where(function ($q) {
                $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', date('Y-m-d H:i:s', time() - 43200));
            })
            ->limit(50)
            ->get();
        foreach ($rows as $row) {
            try {
                $status = $this->subscriptions->sync($row->subscription_id);
                $this->stats['subscriptions_synced']++;
                if (in_array($status, SubscriptionService::ACTIVE_STATUSES, true)) {
                    $result = $this->subscriptions->maintain(Store::subscription($row->subscription_id));
                    if ($result !== 'ok') {
                        $this->say('subscription ' . $row->subscription_id . ': ' . $result);
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->say('subscription ' . $row->subscription_id . ': error ' . $e->getMessage());
            }
        }
    }

    private function count($outcome, $renewal = false)
    {
        switch ($outcome) {
            case 'applied':
                $this->stats[$renewal ? 'renewals_applied' : 'applied']++;
                break;
            case 'credited':
                $this->stats['credited']++;
                break;
            case 'expired':
            case 'cancelled':
                $this->stats['expired']++;
                break;
        }
    }

    /** Re-check every checkout for one invoice right now (admin "verify" button). */
    public function verifyInvoice($invoiceId)
    {
        $results = [];
        $rows = Capsule::table(Schema::T_CHECKOUTS)
            ->where('invoice_id', (int) $invoiceId)
            ->whereNotNull('checkout_id')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();
        foreach ($rows as $row) {
            try {
                $results[$row->checkout_id] = $this->processor->processCheckout($row, 'admin');
            } catch (\Exception $e) {
                $results[$row->checkout_id] = 'error: ' . $e->getMessage();
            }
        }
        return $results;
    }
}
