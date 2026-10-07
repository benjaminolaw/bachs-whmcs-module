<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Data access for the module tables.
 */
class Store
{
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ kv

    public static function kvGet($key, $maxAgeSeconds = null)
    {
        $row = Capsule::table(Schema::T_KV)->where('k', $key)->first();
        if (!$row) {
            return null;
        }
        if ($maxAgeSeconds !== null && strtotime($row->updated_at) < time() - $maxAgeSeconds) {
            return null;
        }
        return $row->v;
    }

    public static function kvSet($key, $value)
    {
        Capsule::table(Schema::T_KV)->updateOrInsert(['k' => $key], ['v' => $value, 'updated_at' => self::now()]);
    }

    // ------------------------------------------------------------- checkouts

    public static function insertCheckout(array $data)
    {
        $data['created_at'] = $data['updated_at'] = self::now();
        return Capsule::table(Schema::T_CHECKOUTS)->insertGetId($data);
    }

    public static function updateCheckout($id, array $data)
    {
        $data['updated_at'] = self::now();
        Capsule::table(Schema::T_CHECKOUTS)->where('id', $id)->update($data);
    }

    public static function checkoutByCheckoutId($checkoutId)
    {
        return Capsule::table(Schema::T_CHECKOUTS)->where('checkout_id', $checkoutId)->first();
    }

    public static function checkoutByReference($reference)
    {
        return Capsule::table(Schema::T_CHECKOUTS)->where('reference', $reference)->first();
    }

    /**
     * An OPEN checkout for the same invoice/amount/mode that has not expired
     * can be reused, which stops page refreshes from spawning new sessions.
     */
    public static function reusableCheckout($invoiceId, $mode, $gatewayAmount, $gatewayCurrency, $sandbox)
    {
        return Capsule::table(Schema::T_CHECKOUTS)
            ->where('invoice_id', $invoiceId)
            ->where('mode', $mode)
            ->where('gateway_amount', $gatewayAmount)
            ->where('gateway_currency', $gatewayCurrency)
            ->where('sandbox', $sandbox ? 1 : 0)
            ->where('status', 'open')
            ->whereNotNull('checkout_url')
            ->where('expires_at', '>', date('Y-m-d H:i:s', time() + 300))
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function openCheckoutsForInvoice($invoiceId)
    {
        return Capsule::table(Schema::T_CHECKOUTS)
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['open', 'underpaid', 'error'])
            ->whereNotNull('checkout_id')
            ->get();
    }

    // -------------------------------------------------------------- payments

    public static function paymentById($paymentId)
    {
        return Capsule::table(Schema::T_PAYMENTS)->where('payment_id', $paymentId)->first();
    }

    /**
     * Atomically claim a Bachs payment for processing.
     *
     * Returns true when this process now owns the payment. A row left in
     * "error" (a previous attempt crashed or WHMCS rejected it) or "unmatched"
     * (renewal parked until its invoice exists) can be re-claimed; anything
     * else means another process already handled it.
     */
    public static function claimPayment($paymentId, array $data)
    {
        $data['payment_id'] = $paymentId;
        $data['status'] = 'processing';
        $data['attempts'] = 1;
        $data['created_at'] = $data['updated_at'] = self::now();
        try {
            Capsule::table(Schema::T_PAYMENTS)->insert($data);
            return true;
        } catch (\Exception $e) {
            // Unique key violation: row exists. Re-claim only from error/stale processing.
            $staleBefore = date('Y-m-d H:i:s', time() - 600);
            $affected = Capsule::table(Schema::T_PAYMENTS)
                ->where('payment_id', $paymentId)
                ->where(function ($q) use ($staleBefore) {
                    $q->whereIn('status', ['error', 'unmatched'])
                        ->orWhere(function ($q2) use ($staleBefore) {
                            $q2->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                        });
                })
                ->update([
                    'status' => 'processing',
                    'attempts' => Capsule::raw('attempts + 1'),
                    'updated_at' => self::now(),
                ]);
            return $affected === 1;
        }
    }

    public static function updatePayment($paymentId, array $data)
    {
        $data['updated_at'] = self::now();
        Capsule::table(Schema::T_PAYMENTS)->where('payment_id', $paymentId)->update($data);
    }

    // ---------------------------------------------------------------- events

    /**
     * Register a webhook event. Returns false when the event was already
     * processed (duplicate delivery), true when it should be processed.
     */
    public static function beginEvent($eventId, $eventType)
    {
        $row = Capsule::table(Schema::T_EVENTS)->where('event_id', $eventId)->first();
        if ($row && in_array($row->status, ['processed', 'ignored'], true)) {
            return false;
        }
        if ($row) {
            Capsule::table(Schema::T_EVENTS)->where('id', $row->id)->update([
                'attempts' => $row->attempts + 1,
                'updated_at' => self::now(),
            ]);
            return true;
        }
        try {
            Capsule::table(Schema::T_EVENTS)->insert([
                'event_id' => $eventId,
                'event_type' => mb_substr($eventType, 0, 80),
                'status' => 'received',
                'attempts' => 1,
                'created_at' => self::now(),
                'updated_at' => self::now(),
            ]);
        } catch (\Exception $e) {
            // Concurrent delivery of the same event; the other request handles it.
            return false;
        }
        return true;
    }

    public static function finishEvent($eventId, $status, $error = null)
    {
        Capsule::table(Schema::T_EVENTS)->where('event_id', $eventId)->update([
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'updated_at' => self::now(),
        ]);
    }

    // --------------------------------------------------------- subscriptions

    public static function subscription($subscriptionId)
    {
        return Capsule::table(Schema::T_SUBSCRIPTIONS)->where('subscription_id', $subscriptionId)->first();
    }

    public static function saveSubscription($subscriptionId, array $data)
    {
        $data['updated_at'] = self::now();
        $exists = Capsule::table(Schema::T_SUBSCRIPTIONS)->where('subscription_id', $subscriptionId)->exists();
        if ($exists) {
            Capsule::table(Schema::T_SUBSCRIPTIONS)->where('subscription_id', $subscriptionId)->update($data);
        } else {
            $data['subscription_id'] = $subscriptionId;
            $data['created_at'] = self::now();
            Capsule::table(Schema::T_SUBSCRIPTIONS)->insert($data);
        }
    }

    public static function subscriptionsForClient($clientId)
    {
        return Capsule::table(Schema::T_SUBSCRIPTIONS)->where('client_id', $clientId)->orderBy('id', 'desc')->get();
    }

    /** Active Bachs subscription covering a WHMCS item (Hosting/Addon/Domain). */
    public static function subscriptionForItem($type, $relid)
    {
        $needle = '"type":"' . $type . '","relid":' . (int) $relid . '}';
        return Capsule::table(Schema::T_SUBSCRIPTIONS)
            ->whereIn('status', ['active', 'trialing', 'past_due', 'pending'])
            ->where('items', 'like', '%' . $needle . '%')
            ->orderBy('id', 'desc')
            ->first();
    }

    // ------------------------------------------------------------- customers

    public static function customerId($clientId, $sandbox)
    {
        return Capsule::table(Schema::T_CUSTOMERS)
            ->where('client_id', $clientId)
            ->where('sandbox', $sandbox ? 1 : 0)
            ->value('customer_id');
    }

    public static function saveCustomerId($clientId, $sandbox, $customerId)
    {
        Capsule::table(Schema::T_CUSTOMERS)->updateOrInsert(
            ['client_id' => $clientId, 'sandbox' => $sandbox ? 1 : 0],
            ['customer_id' => $customerId, 'created_at' => self::now()]
        );
    }

    // -------------------------------------------------------------- products

    public static function productId($cacheKey)
    {
        return Capsule::table(Schema::T_PRODUCTS)->where('cache_key', $cacheKey)->value('product_id');
    }

    public static function saveProductId($cacheKey, $productId)
    {
        Capsule::table(Schema::T_PRODUCTS)->updateOrInsert(
            ['cache_key' => $cacheKey],
            ['product_id' => $productId, 'created_at' => self::now()]
        );
    }

    // ----------------------------------------------------------------- locks

    /** MySQL advisory lock; returns false immediately if another process holds it. */
    public static function lock($name)
    {
        $row = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS l', ['bachs_' . $name]);
        return $row && (int) $row->l === 1;
    }

    public static function unlock($name)
    {
        Capsule::selectOne('SELECT RELEASE_LOCK(?) AS l', ['bachs_' . $name]);
    }
}
