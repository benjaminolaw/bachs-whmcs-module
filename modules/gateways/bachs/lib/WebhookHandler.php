<?php

namespace HarmonWeb\Bachs;

/**
 * Processes signed webhook deliveries from Bachs.
 *
 * Flow: verify signature + timestamp -> de-duplicate by event ID -> extract
 * the IDs the event refers to -> re-fetch the authoritative object from the
 * API -> apply. Event bodies are never trusted for amounts or status.
 *
 * Response codes: 200 when handled/ignored/duplicate, 400 for bad signature
 * or payload, 500 for transient failures so Bachs retries the delivery.
 */
class WebhookHandler
{
    const MAX_BODY_BYTES = 1048576;

    const PAYMENT_EVENTS = [
        'checkout.completed',
        'checkout.expired',
        'collection.succeeded',
        'collection.failed',
        'collection.underpaid',
        'invoice.paid',
    ];

    const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_failed',
    ];

    /** Event types to subscribe the endpoint to. */
    const SUBSCRIBED_EVENTS = [
        'checkout.completed',
        'checkout.expired',
        'collection.succeeded',
        'collection.failed',
        'collection.underpaid',
        'invoice.paid',
        'invoice.payment_failed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'refund.paid',
        'refund.failed',
    ];

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
     * @return array [int httpStatus, array responseBody]
     */
    public function handle($rawBody, array $headers)
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return [413, ['error' => 'payload too large']];
        }

        try {
            WebhookVerifier::verify(
                $rawBody,
                $headers['x-bachs-signature'] ?? '',
                $headers['x-bachs-timestamp'] ?? '',
                $this->config->webhookSecret()
            );
        } catch (\InvalidArgumentException $e) {
            Logger::transaction(['error' => 'Webhook rejected: ' . $e->getMessage(), 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''], 'Invalid Signature');
            return [400, ['error' => 'invalid signature']];
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return [400, ['error' => 'invalid json']];
        }

        $eventId = (string) ($event['id'] ?? ($event['event_id'] ?? ''));
        if ($eventId === '') {
            $eventId = 'sha256:' . hash('sha256', $rawBody);
        }
        $eventType = (string) ($event['event_type'] ?? ($event['type'] ?? 'unknown'));

        if (!Store::beginEvent($eventId, $eventType)) {
            return [200, ['status' => 'duplicate']];
        }

        try {
            $outcome = $this->dispatch($eventType, $event);
            Store::finishEvent($eventId, $outcome === 'ignored' ? 'ignored' : 'processed');
            return [200, ['status' => $outcome]];
        } catch (ApiException $e) {
            Store::finishEvent($eventId, 'failed', $e->getMessage());
            Logger::transaction(['event_id' => $eventId, 'event_type' => $eventType, 'error' => $e->getMessage()], 'Webhook Error');
            // Retry only makes sense when the failure is transient.
            return $e->isRetryable() ? [500, ['error' => 'temporary failure']] : [200, ['status' => 'error']];
        } catch (\Throwable $e) {
            Store::finishEvent($eventId, 'failed', $e->getMessage());
            Logger::transaction(['event_id' => $eventId, 'event_type' => $eventType, 'error' => $e->getMessage()], 'Webhook Error');
            return [500, ['error' => 'processing failed']];
        }
    }

    private function dispatch($type, array $event)
    {
        $ids = self::extractIds($event);
        $processor = new PaymentProcessor($this->config, $this->api);
        $subscriptions = new SubscriptionService($this->config, $this->api);

        if (in_array($type, self::PAYMENT_EVENTS, true)) {
            $row = null;
            if ($ids['checkout_id']) {
                $row = Store::checkoutByCheckoutId($ids['checkout_id']);
            }
            if (!$row && $ids['reference']) {
                $row = Store::checkoutByReference($ids['reference']);
            }
            if ($row) {
                $outcome = $processor->processCheckout($row, 'webhook:' . $type);
                if ($type === 'collection.underpaid' && $outcome === 'pending') {
                    Store::updateCheckout($row->id, ['status' => 'underpaid']);
                    Logger::transaction(['invoice_id' => $row->invoice_id, 'checkout_id' => $row->checkout_id, 'note' => 'Customer underpaid; awaiting balance'], 'Underpaid');
                }
                return $outcome;
            }
            if ($ids['payment_id']) {
                return $processor->processPaymentId($ids['payment_id'], 'webhook:' . $type);
            }
            if ($type === 'invoice.paid' && $ids['subscription_id']) {
                // Bachs subscription invoice without a payment reference in the
                // body; the reconciler's payment sweep will pick the charge up.
                $subscriptions->sync($ids['subscription_id']);
                return 'deferred';
            }
            return 'ignored';
        }

        if (in_array($type, self::SUBSCRIPTION_EVENTS, true)) {
            if (!$ids['subscription_id']) {
                return 'ignored';
            }
            if ($type === 'customer.subscription.created' && !Store::subscription($ids['subscription_id'])) {
                return $subscriptions->adoptOrphan($ids['subscription_id']) ? 'linked' : 'ignored';
            }
            $status = $subscriptions->sync($ids['subscription_id']);
            if ($status === null) {
                return 'ignored';
            }
            if ($type === 'invoice.payment_failed') {
                $local = Store::subscription($ids['subscription_id']);
                Logger::transaction(['subscription_id' => $ids['subscription_id'], 'status' => $status, 'note' => 'Automatic renewal charge failed; Bachs will retry'], 'Renewal Failed');
                if ($local) {
                    Logger::activity('Bachs auto-pay charge failed for subscription ' . $ids['subscription_id'], $local->client_id);
                }
            }
            return 'synced';
        }

        if (strpos($type, 'refund.') === 0) {
            Logger::transaction(['event' => $type, 'refund_id' => $ids['refund_id'], 'payment_id' => $ids['payment_id']], 'Refund Update');
            return 'logged';
        }

        return 'ignored';
    }

    /**
     * Collect the identifiers an event refers to, whatever the exact payload
     * nesting (`data`, `object`, `payload`, top-level entity_type/entity_id).
     */
    public static function extractIds(array $event)
    {
        $ids = [
            'checkout_id' => null,
            'payment_id' => null,
            'subscription_id' => null,
            'reference' => null,
            'refund_id' => null,
        ];

        $entityType = strtolower((string) ($event['entity_type'] ?? ''));
        $entityId = isset($event['entity_id']) ? (string) $event['entity_id'] : null;
        if ($entityId) {
            if (strpos($entityType, 'checkout') !== false) {
                $ids['checkout_id'] = $entityId;
            } elseif (in_array($entityType, ['charge', 'payment', 'collection'], true)) {
                $ids['payment_id'] = $entityId;
            } elseif (strpos($entityType, 'subscription') !== false) {
                $ids['subscription_id'] = $entityId;
            } elseif ($entityType === 'refund') {
                $ids['refund_id'] = $entityId;
            }
        }

        $object = null;
        foreach (['data', 'object', 'payload'] as $k) {
            if (isset($event[$k]) && is_array($event[$k])) {
                $object = $event[$k];
                break;
            }
        }
        if (is_array($object) && isset($object['object']) && is_array($object['object'])) {
            $object = $object['object'];
        }
        $object = is_array($object) ? $object : $event;

        $scan = function (array $node, $depth) use (&$scan, &$ids) {
            if ($depth > 4) {
                return;
            }
            $map = [
                'checkout_id' => 'checkout_id',
                'payment_id' => 'payment_id',
                'charge_id' => 'payment_id',
                'subscription_id' => 'subscription_id',
                'reference' => 'reference',
                'refund_id' => 'refund_id',
            ];
            foreach ($map as $key => $target) {
                if ($ids[$target] === null && isset($node[$key]) && is_scalar($node[$key]) && $node[$key] !== '') {
                    $ids[$target] = (string) $node[$key];
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $scan($value, $depth + 1);
                }
            }
        };
        $scan($object, 0);

        // A bare "id" on the object, interpreted by its prefix.
        if (isset($object['id']) && is_string($object['id'])) {
            $id = $object['id'];
            if ($ids['checkout_id'] === null && strpos($id, 'chk_') === 0) {
                $ids['checkout_id'] = $id;
            } elseif ($ids['subscription_id'] === null && strpos($id, 'sub_') === 0) {
                $ids['subscription_id'] = $id;
            } elseif ($ids['payment_id'] === null && (strpos($id, 'pay_') === 0 || strpos($id, 'ch_') === 0)) {
                $ids['payment_id'] = $id;
            }
        }

        // Only our own references are meaningful.
        if ($ids['reference'] !== null && strpos($ids['reference'], 'WHMCS-') !== 0) {
            $ids['reference'] = null;
        }
        return $ids;
    }
}
