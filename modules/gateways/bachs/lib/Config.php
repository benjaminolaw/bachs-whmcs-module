<?php

namespace HarmonWeb\Bachs;

/**
 * Typed access to the gateway settings configured in
 * Setup > Payments > Payment Gateways > Bachs.
 */
class Config
{
    const PRODUCTION_BASE_URL = 'https://api.bachs.io';
    const SANDBOX_BASE_URL = 'https://sandbox-api.bachs.io';

    /** @var array */
    private $params;

    /** @var Config|null */
    private static $instance;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * Load the active gateway configuration from WHMCS.
     *
     * @throws \RuntimeException when the gateway is not activated
     */
    public static function load()
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (!function_exists('getGatewayVariables')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }
        $params = getGatewayVariables(BACHS_GATEWAY);
        if (empty($params['type'])) {
            throw new \RuntimeException('The Bachs gateway module is not activated.');
        }
        self::$instance = new self($params);
        return self::$instance;
    }

    public static function fromParams(array $params)
    {
        self::$instance = new self($params);
        return self::$instance;
    }

    public function raw($key, $default = null)
    {
        return isset($this->params[$key]) && $this->params[$key] !== '' ? $this->params[$key] : $default;
    }

    private function flag($key)
    {
        $v = $this->raw($key, '');
        return $v === 'on' || $v === '1' || $v === 1 || $v === true || $v === 'yes';
    }

    public function isSandbox()
    {
        return $this->flag('testMode');
    }

    public function secretKey()
    {
        return trim((string) ($this->isSandbox() ? $this->raw('sandboxSecretKey', '') : $this->raw('liveSecretKey', '')));
    }

    /** Signing secret (whsec_...) of the webhook endpoint for the active mode. */
    public function webhookSecret()
    {
        return trim((string) ($this->isSandbox() ? $this->raw('sandboxWebhookSecret', '') : $this->raw('liveWebhookSecret', '')));
    }

    public function baseUrl()
    {
        return $this->isSandbox() ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    /**
     * Guard against a live key in sandbox mode and vice versa, which would
     * otherwise silently send real customers to the wrong environment.
     */
    public function validateKey()
    {
        $key = $this->secretKey();
        if ($key === '') {
            throw new \RuntimeException('Bachs secret key is not configured for ' . ($this->isSandbox() ? 'sandbox' : 'live') . ' mode.');
        }
        if ($this->isSandbox() && strpos($key, 'sk_sandbox_') !== 0) {
            throw new \RuntimeException('Sandbox mode is enabled but the sandbox key does not start with sk_sandbox_.');
        }
        if (!$this->isSandbox() && strpos($key, 'sk_sandbox_') === 0) {
            throw new \RuntimeException('Live mode is enabled but a sandbox key (sk_sandbox_) is configured as the live key.');
        }
    }

    /** @return string[] payment method types sent as allowed_payment_method_types */
    public function allowedPaymentMethods()
    {
        $map = [
            'allowCard' => 'card',
            'allowCrypto' => 'crypto',
            'allowBankTransfer' => 'bank_transfer',
            'allowMobileMoney' => 'mobile_money',
        ];
        $methods = [];
        foreach ($map as $field => $type) {
            if ($this->flag($field)) {
                $methods[] = $type;
            }
        }
        return $methods;
    }

    public function autopayEnabled()
    {
        return $this->flag('enableAutopay');
    }

    /** Allow auto-pay for non-USD invoices by converting at WHMCS exchange rates. */
    public function autopayConvertNonUsd()
    {
        return $this->flag('autopayConvert');
    }

    public function checkoutExpiryMinutes()
    {
        $v = (int) $this->raw('checkoutExpiry', 60);
        return max(15, min(1440, $v ?: 60));
    }

    public function reconcileLookbackDays()
    {
        $v = (int) $this->raw('reconcileDays', 7);
        return max(1, min(60, $v ?: 7));
    }

    /** Days after which an unmatched renewal payment is added as account credit; 0 = never. */
    public function unmatchedCreditDays()
    {
        $v = $this->raw('unmatchedCreditDays', 3);
        return max(0, min(60, (int) $v));
    }

    /** Percentage price drift that triggers a subscription price update. */
    public function priceSyncThreshold()
    {
        $v = (float) $this->raw('priceSyncThreshold', 1);
        return max(0.0, $v) / 100.0;
    }

    public function priceSyncEnabled()
    {
        return $this->flag('priceSync');
    }

    public function debug()
    {
        return $this->flag('debugLogging');
    }

    public function systemUrl()
    {
        $url = (string) $this->raw('systemurl', '');
        if ($url === '' && class_exists('\WHMCS\Config\Setting')) {
            $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        }
        return rtrim($url, '/') . '/';
    }

    public function buttonText()
    {
        return (string) $this->raw('buttonText', 'Pay Now');
    }

    /**
     * Server-side key used to sign "pay" form tokens. Derived from the
     * secret key so it rotates with it and never leaves the server.
     */
    public function signingKey()
    {
        $base = $this->secretKey();
        if (isset($GLOBALS['cc_encryption_hash'])) {
            $base .= '|' . $GLOBALS['cc_encryption_hash'];
        }
        return hash('sha256', 'bachs-whmcs-form|' . $base);
    }

    public function convertTo()
    {
        return $this->raw('convertto');
    }
}
