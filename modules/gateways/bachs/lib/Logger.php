<?php

namespace HarmonWeb\Bachs;

/**
 * Thin wrapper over WHMCS logging.
 *
 *  - Gateway Log (Billing > Gateway Log): payment outcomes via logTransaction().
 *  - Module Log (Utilities > Logs > Module Log): API traffic via logModuleCall(),
 *    only when "Debug Logging" is enabled. Secrets are always masked.
 *  - Activity Log: important operational events via logActivity().
 */
class Logger
{
    /** @var string[] values that must never appear in logs */
    private static $secrets = [];

    public static function addSecret($value)
    {
        $value = (string) $value;
        if (strlen($value) >= 8) {
            self::$secrets[] = $value;
        }
    }

    public static function mask($data)
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match('/(secret|authorization|api[_-]?key|password|token|signature|portal_url|^url$)/i', $k)) {
                    $out[$k] = '***';
                } else {
                    $out[$k] = self::mask($v);
                }
            }
            return $out;
        }
        if (is_string($data) && self::$secrets) {
            return str_replace(self::$secrets, '***', $data);
        }
        return $data;
    }

    public static function transaction($data, $status)
    {
        if (!function_exists('logTransaction')) {
            if (defined('ROOTDIR')) {
                require_once ROOTDIR . '/includes/gatewayfunctions.php';
            }
        }
        if (function_exists('logTransaction')) {
            logTransaction(BACHS_GATEWAY, self::mask($data), $status);
        }
    }

    public static function api($action, $request, $response, $processed = '')
    {
        try {
            if (!Config::load()->debug()) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        if (function_exists('logModuleCall')) {
            logModuleCall(
                BACHS_GATEWAY,
                $action,
                self::mask($request),
                self::mask($response),
                self::mask($processed),
                self::$secrets
            );
        }
    }

    public static function activity($message, $clientId = 0)
    {
        if (function_exists('logActivity')) {
            logActivity('[Bachs] ' . self::mask($message), (int) $clientId);
        }
    }

    public static function debug($action, $data)
    {
        self::api($action, $data, '', '');
    }
}
