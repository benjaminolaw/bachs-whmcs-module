<?php

/**
 * In-memory Bachs API used as the ApiClient transport in tests.
 * Implements just enough of the real endpoints, with the same shapes.
 */
class FakeBachs
{
    public $sessions = [];
    public $payments = [];
    public $customers = [];
    public $products = [];
    public $subscriptions = [];
    public $refunds = [];
    public $requests = [];
    public $idempotency = [];
    public $failNext = null; // [status, body]
    private $seq = 1;

    private function id($prefix)
    {
        return $prefix . '_' . str_pad((string) $this->seq++, 6, '0', STR_PAD_LEFT);
    }

    public function __invoke($method, $url, array $headers, $payload)
    {
        $this->requests[] = [$method, $url, $payload];
        if ($this->failNext) {
            $f = $this->failNext;
            $this->failNext = null;
            return [$f[0], [], json_encode($f[1])];
        }
        $idem = null;
        foreach ($headers as $h) {
            if (stripos($h, 'Idempotency-Key:') === 0) {
                $idem = trim(substr($h, 16));
            }
        }
        if ($idem !== null && isset($this->idempotency[$idem])) {
            return [200, [], json_encode($this->idempotency[$idem])];
        }

        $path = parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = $payload ? json_decode($payload, true) : [];

        list($status, $resp) = $this->route($method, $path, $body, $query);
        if ($idem !== null && $status < 300) {
            $this->idempotency[$idem] = $resp;
        }
        return [$status, [], json_encode($resp)];
    }

    private function route($method, $path, $body, $query)
    {
        if ($method === 'POST' && $path === '/v1/checkout-sessions') {
            $id = $this->id('chk');
            $amount = isset($body['pricing']) ? $body['pricing']['amount'] : null;
            $currency = isset($body['pricing']) ? $body['pricing']['currency'] : null;
            $recurring = null;
            if (isset($body['product_cart'])) {
                $p = $this->products[$body['product_cart'][0]['product_id']];
                $amount = $p['price']['amount'];
                $currency = $p['price']['currency'];
                $recurring = ['interval' => $p['billing_cycle']['interval'], 'interval_count' => $p['billing_cycle']['frequency']];
            }
            $this->sessions[$id] = [
                'checkout_id' => $id,
                'status' => 'OPEN',
                'payment_status' => 'requires_payment_method',
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $body['reference'],
                'metadata' => $body['metadata'],
                'customer' => isset($body['customer']['customer_id']) ? ['id' => $body['customer']['customer_id'], 'email' => 'x@example.com'] : ['id' => null, 'email' => $body['customer']['email']],
                'charge' => null,
                'recurring' => $recurring,
                'product_cart' => $body['product_cart'] ?? null,
                'allowed' => $body['allowed_payment_method_types'] ?? null,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            ];
            return [201, [
                'checkout_id' => $id,
                'checkout_url' => 'https://checkout.bachs.io/c/' . $id,
                'status' => 'OPEN',
                'expires_at' => gmdate('c', time() + 3600),
                'created_at' => gmdate('c'),
            ]];
        }
        if ($method === 'GET' && preg_match('#^/v1/checkout-sessions/(.+)$#', $path, $m)) {
            return isset($this->sessions[$m[1]]) ? [200, $this->sessions[$m[1]]] : [404, ['detail' => 'not found', 'error_code' => 'not_found']];
        }
        if ($method === 'GET' && $path === '/v1/payments') {
            $items = [];
            foreach (array_reverse($this->payments) as $p) {
                $items[] = [
                    'id' => $p['payment_id'],
                    'reference' => $p['reference'],
                    'status' => $p['status'],
                    'amount' => $p['amount'],
                    'currency' => $p['currency'],
                    'customer_name' => '',
                    'customer_email' => $p['customer_email'] ?? '',
                    'transaction_date' => $p['created_at'],
                ];
            }
            $offset = (int) ($query['offset'] ?? 0);
            $limit = (int) ($query['limit'] ?? 100);
            $page = array_slice($items, $offset, $limit);
            return [200, ['items' => $page, 'pagination' => ['has_more' => $offset + $limit < count($items), 'limit' => $limit, 'offset' => $offset, 'returned' => count($page), 'total' => count($items)]]];
        }
        if ($method === 'GET' && preg_match('#^/v1/payments/(.+)$#', $path, $m)) {
            return isset($this->payments[$m[1]]) ? [200, $this->payments[$m[1]]] : [404, ['detail' => 'not found']];
        }
        if ($method === 'GET' && $path === '/v1/currencies/supported') {
            return [200, ['fiat' => ['USD', 'NGN', 'GHS', 'KES'], 'crypto' => ['USDT_TRC20', 'USDC']]];
        }
        if ($method === 'POST' && $path === '/v1/customers') {
            $id = $this->id('cust');
            $this->customers[$id] = $body + ['customer_id' => $id];
            return [201, ['customer_id' => $id, 'email' => $body['email'], 'metadata' => $body['metadata'] ?? [], 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')]];
        }
        if ($method === 'POST' && preg_match('#^/v1/customers/(.+)/portal-sessions$#', $path, $m)) {
            return [201, ['id' => 'psn_1', 'url' => 'https://portal.bachs.io/s/secret']];
        }
        if ($method === 'POST' && $path === '/v1/products') {
            $id = $this->id('prod');
            $this->products[$id] = $body + ['id' => $id];
            return [201, $this->products[$id]];
        }
        if ($method === 'GET' && $path === '/v1/subscriptions') {
            $items = array_values(array_filter($this->subscriptions, function ($s) use ($query) {
                return empty($query['customer_id']) || $s['customer']['customer_id'] === $query['customer_id'];
            }));
            return [200, ['items' => $items]];
        }
        if (preg_match('#^/v1/subscriptions/(.+)$#', $path, $m)) {
            if (!isset($this->subscriptions[$m[1]])) {
                return [404, ['detail' => 'not found']];
            }
            if ($method === 'GET') {
                return [200, $this->subscriptions[$m[1]]];
            }
            if ($method === 'DELETE') {
                $this->subscriptions[$m[1]]['status'] = 'canceled';
                return [200, $this->subscriptions[$m[1]]];
            }
            if ($method === 'PATCH') {
                if (isset($body['product_id'])) {
                    $p = $this->products[$body['product_id']];
                    $this->subscriptions[$m[1]]['product'] = ['id' => $p['id']];
                    $this->subscriptions[$m[1]]['amount'] = $p['price']['amount'];
                }
                return [200, $this->subscriptions[$m[1]]];
            }
        }
        if ($method === 'POST' && $path === '/v1/refunds') {
            $id = $this->id('rf');
            $this->refunds[$id] = $body;
            return [201, ['refund_id' => $id, 'charge_id' => $body['charge_id'], 'status' => 'processing', 'requested_amount' => $body['amount'] ?? null]];
        }
        if ($method === 'GET' && $path === '/v1/webhooks/endpoints') {
            return [200, []];
        }
        if ($method === 'POST' && $path === '/v1/webhooks/endpoints') {
            return [201, ['endpoint_id' => 'we_1', 'signing_secret' => 'whsec_new'] + $body];
        }
        return [404, ['detail' => 'no route ' . $method . ' ' . $path]];
    }

    // ------------------------------------------------------------ simulations

    /** Customer pays the checkout. Returns the payment id. */
    public function pay($checkoutId, $amountPaid = null, $paymentMethod = 'card')
    {
        $s = &$this->sessions[$checkoutId];
        $pid = $this->id('pay');
        $subscriptionId = null;
        if ($s['recurring']) {
            $subscriptionId = $this->id('sub');
            $productId = $s['product_cart'][0]['product_id'];
            $this->subscriptions[$subscriptionId] = [
                'id' => $subscriptionId,
                'customer' => ['customer_id' => $s['customer']['id']],
                'payment_method_id' => 'pm_1',
                'status' => 'active',
                'currency' => $s['currency'],
                'amount' => $s['amount'],
                'billing_cycle' => ['interval' => $s['recurring']['interval'], 'frequency' => $s['recurring']['interval_count']],
                'next_billed_at' => gmdate('c', time() + 30 * 86400),
                'product' => ['id' => $productId],
                'created_at' => gmdate('c'),
            ];
        }
        $payment = [
            'payment_id' => $pid,
            'reference' => $s['reference'],
            'checkout_id' => $checkoutId,
            'billing_reason' => $subscriptionId ? 'subscription_create' : 'purchase',
            'status' => 'COMPLETED',
            'amount' => $s['amount'],
            'amount_paid' => $amountPaid === null ? $s['amount'] : $amountPaid,
            'currency' => $s['currency'],
            'fee_usd' => '0.30',
            'payment_method' => $paymentMethod,
            'subscription_id' => $subscriptionId,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        $this->payments[$pid] = $payment;
        $s['status'] = 'COMPLETED';
        $s['payment_status'] = 'succeeded';
        $s['charge'] = $payment;
        $s['completed_at'] = gmdate('c');
        return $pid;
    }

    /** Bachs charges the saved card for the next cycle. */
    public function renew($subscriptionId, $amount = null)
    {
        $sub = $this->subscriptions[$subscriptionId];
        $pid = $this->id('pay');
        $this->payments[$pid] = [
            'payment_id' => $pid,
            'reference' => 'sub-cycle-' . $pid,
            'checkout_id' => null,
            'billing_reason' => 'subscription_cycle',
            'status' => 'COMPLETED',
            'amount' => $amount ?: $sub['amount'],
            'amount_paid' => $amount ?: $sub['amount'],
            'currency' => $sub['currency'],
            'fee_usd' => '0.30',
            'subscription_id' => $subscriptionId,
            'invoice' => ['invoice_id' => 'inv_' . $pid, 'subscription_id' => $subscriptionId, 'kind' => 'cycle'],
            'customer_email' => 'usd@example.com',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        return $pid;
    }
}
