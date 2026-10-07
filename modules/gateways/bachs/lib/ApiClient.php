<?php

namespace HarmonWeb\Bachs;

/**
 * Minimal Bachs REST client (https://docs.bachs.io).
 *
 *  - Bearer authentication with sk_live_/sk_sandbox_ keys
 *  - TLS verification always on, strict timeouts
 *  - Idempotency-Key on every mutating request
 *  - Automatic retry with backoff for 429/5xx/transport errors
 */
class ApiClient
{
    const USER_AGENT = 'HarmonWeb-WHMCS-Bachs/';
    const MAX_RETRIES = 2;

    /** @var string */
    private $secretKey;

    /** @var string */
    private $baseUrl;

    /** @var callable|null test seam: function($method, $url, $headers, $body) => [status, headers, body] */
    private $transport;

    public function __construct($secretKey, $baseUrl, ?callable $transport = null)
    {
        $this->secretKey = (string) $secretKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
        Logger::addSecret($this->secretKey);
    }

    /** @var callable|null transport used by fromConfig(); set only by the test suite */
    public static $defaultTransport;

    public static function fromConfig(Config $config)
    {
        $config->validateKey();
        return new self($config->secretKey(), $config->baseUrl(), self::$defaultTransport);
    }

    // ---------------------------------------------------------------- checkout

    public function createCheckoutSession(array $body, $idempotencyKey)
    {
        return $this->request('POST', '/v1/checkout-sessions', $body, $idempotencyKey);
    }

    public function getCheckoutSession($checkoutId)
    {
        return $this->request('GET', '/v1/checkout-sessions/' . rawurlencode($checkoutId));
    }

    // ---------------------------------------------------------------- payments

    public function getPayment($paymentId)
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    public function getChargeStatus($chargeId)
    {
        return $this->request('GET', '/v1/payments/charges/' . rawurlencode($chargeId));
    }

    public function listPayments($limit = 100, $offset = 0, $statusFilter = null)
    {
        $query = ['limit' => (int) $limit, 'offset' => (int) $offset];
        if ($statusFilter) {
            $query['status_filter'] = $statusFilter;
        }
        return $this->request('GET', '/v1/payments', null, null, $query);
    }

    public function supportedCurrencies()
    {
        return $this->request('GET', '/v1/currencies/supported');
    }

    // --------------------------------------------------------------- customers

    public function createCustomer(array $body, $idempotencyKey)
    {
        return $this->request('POST', '/v1/customers', $body, $idempotencyKey);
    }

    public function getCustomer($customerId)
    {
        return $this->request('GET', '/v1/customers/' . rawurlencode($customerId));
    }

    public function updateCustomer($customerId, array $body, $idempotencyKey)
    {
        return $this->request('PATCH', '/v1/customers/' . rawurlencode($customerId), $body, $idempotencyKey);
    }

    public function createPortalSession($customerId, $idempotencyKey)
    {
        return $this->request('POST', '/v1/customers/' . rawurlencode($customerId) . '/portal-sessions', null, $idempotencyKey);
    }

    // ---------------------------------------------------------------- products

    public function createProduct(array $body, $idempotencyKey)
    {
        return $this->request('POST', '/v1/products', $body, $idempotencyKey);
    }

    public function getProduct($productId)
    {
        return $this->request('GET', '/v1/products/' . rawurlencode($productId));
    }

    // ----------------------------------------------------------- subscriptions

    public function getSubscription($subscriptionId)
    {
        return $this->request('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
    }

    public function updateSubscription($subscriptionId, array $body, $idempotencyKey)
    {
        return $this->request('PATCH', '/v1/subscriptions/' . rawurlencode($subscriptionId), $body, $idempotencyKey);
    }

    public function cancelSubscription($subscriptionId, $atPeriodEnd, $reason, $idempotencyKey)
    {
        $body = ['cancel_at_period_end' => (bool) $atPeriodEnd];
        if ($reason) {
            $body['reason'] = mb_substr($reason, 0, 255);
        }
        return $this->request('DELETE', '/v1/subscriptions/' . rawurlencode($subscriptionId), $body, $idempotencyKey);
    }

    // ----------------------------------------------------------------- refunds

    public function createRefund(array $body, $idempotencyKey)
    {
        return $this->request('POST', '/v1/refunds', $body, $idempotencyKey);
    }

    // ---------------------------------------------------------------- webhooks

    public function createWebhookEndpoint($name, $url, array $eventTypes, $idempotencyKey)
    {
        return $this->request('POST', '/v1/webhooks/endpoints', [
            'name' => $name,
            'url' => $url,
            'event_types' => array_values($eventTypes),
        ], $idempotencyKey);
    }

    public function listWebhookEndpoints()
    {
        return $this->request('GET', '/v1/webhooks/endpoints');
    }

    public function replayWebhookEvent(array $body, $idempotencyKey)
    {
        return $this->request('POST', '/v1/webhooks/replay', $body, $idempotencyKey);
    }

    // --------------------------------------------------------------- transport

    /**
     * @return array decoded JSON body (empty array for 204)
     * @throws ApiException
     */
    public function request($method, $path, ?array $body = null, $idempotencyKey = null, array $query = [])
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->secretKey,
            'User-Agent: ' . self::USER_AGENT . (defined('BACHS_MODULE_VERSION') ? BACHS_MODULE_VERSION : 'dev'),
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null && $method !== 'GET') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            list($status, $respHeaders, $raw, $transportError) = $this->send($method, $url, $headers, $payload);

            $decoded = null;
            if ($raw !== '' && $raw !== null) {
                $decoded = json_decode($raw, true);
            }

            Logger::api($method . ' ' . $path, $body === null ? $query : $body, $raw, $decoded ?: []);

            $retryable = $transportError !== null || $status === 429 || $status >= 500;
            if ($retryable && $attempt <= self::MAX_RETRIES) {
                $delay = 0.5 * pow(2, $attempt - 1);
                if ($status === 429 && isset($respHeaders['retry-after']) && is_numeric($respHeaders['retry-after'])) {
                    $delay = max($delay, min(10, (float) $respHeaders['retry-after']));
                }
                usleep((int) ($delay * 1000000));
                continue;
            }

            if ($transportError !== null) {
                throw new ApiException('Could not reach Bachs: ' . $transportError, 0);
            }

            if ($status >= 200 && $status < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            $detail = is_array($decoded) && isset($decoded['detail']) ? $decoded['detail'] : null;
            if (is_array($detail)) {
                $detail = json_encode($detail);
            }
            $message = $detail ?: ('Bachs API returned HTTP ' . $status);
            throw new ApiException(
                $message,
                $status,
                is_array($decoded) && isset($decoded['error_code']) ? $decoded['error_code'] : null,
                is_array($decoded) ? $decoded : []
            );
        }
    }

    /**
     * @return array [int status, array headers(lowercase), string body, string|null transportError]
     */
    private function send($method, $url, array $headers, $payload)
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $method, $url, $headers, $payload);
            return [(int) $result[0], (array) $result[1], (string) $result[2], isset($result[3]) ? $result[3] : null];
        }

        $respHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $error = null;
        if ($raw === false) {
            $error = curl_error($ch) ?: 'unknown cURL error';
            $raw = '';
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $respHeaders, (string) $raw, $error];
    }
}
