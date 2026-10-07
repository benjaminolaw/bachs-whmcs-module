<?php

namespace HarmonWeb\Bachs;

/**
 * Verifies Bachs webhook signatures.
 *
 * Bachs signs each delivery with:
 *   X-Bachs-Timestamp: unix seconds
 *   X-Bachs-Signature: hex(HMAC-SHA256(secret, "{timestamp}.{raw_body}"))
 */
class WebhookVerifier
{
    const TOLERANCE_SECONDS = 300;

    /**
     * @throws \InvalidArgumentException with a safe-to-log reason on failure
     */
    public static function verify($rawBody, $signatureHeader, $timestampHeader, $secret, $now = null, $tolerance = self::TOLERANCE_SECONDS)
    {
        if ($secret === '' || $secret === null) {
            throw new \InvalidArgumentException('Webhook signing secret is not configured');
        }
        $signatureHeader = trim((string) $signatureHeader);
        $timestampHeader = trim((string) $timestampHeader);
        if ($signatureHeader === '' || $timestampHeader === '') {
            throw new \InvalidArgumentException('Missing signature headers');
        }
        if (!preg_match('/^\d{9,11}(\.\d+)?$/', $timestampHeader)) {
            throw new \InvalidArgumentException('Malformed timestamp');
        }
        $ts = (int) $timestampHeader;
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $ts) > $tolerance) {
            throw new \InvalidArgumentException('Timestamp outside tolerance window');
        }

        $expected = self::sign($ts, $rawBody, $secret);

        // Accept a bare hex digest, "sha256=<hex>", or a comma-separated list
        // (e.g. during secret rotation) — any match passes.
        foreach (preg_split('/\s*,\s*/', $signatureHeader) as $candidate) {
            if (stripos($candidate, 'sha256=') === 0) {
                $candidate = substr($candidate, 7);
            } elseif (stripos($candidate, 'v1=') === 0) {
                $candidate = substr($candidate, 3);
            }
            if (hash_equals($expected, strtolower($candidate))) {
                return true;
            }
        }
        throw new \InvalidArgumentException('Signature mismatch');
    }

    public static function sign($timestamp, $rawBody, $secret)
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }
}
