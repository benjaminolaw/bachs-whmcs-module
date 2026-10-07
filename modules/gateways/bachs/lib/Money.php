<?php

namespace HarmonWeb\Bachs;

/**
 * Money helpers.
 *
 * Bachs exchanges money as decimal strings at the currency's precision
 * (e.g. "29.00"). All comparisons here are done on integer minor units to
 * avoid float drift when deciding whether an invoice was paid in full.
 */
class Money
{
    /** Currencies without a minor unit. */
    const ZERO_DECIMAL = ['UGX', 'RWF', 'XAF', 'XOF', 'JPY', 'KRW', 'VND', 'CLP', 'GNF', 'BIF', 'DJF', 'KMF', 'PYG', 'VUV', 'XPF'];

    public static function decimals($currency)
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** Format an amount as the decimal string Bachs expects. */
    public static function format($amount, $currency)
    {
        $decimals = self::decimals($currency);
        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /** Integer minor units (cents/kobo) for exact comparisons. */
    public static function toMinor($amount, $currency = 'USD')
    {
        $amount = is_string($amount) ? trim($amount) : $amount;
        if ($amount === '' || $amount === null || !is_numeric($amount)) {
            return 0;
        }
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Compare two amounts. Returns -1, 0 or 1.
     * $toleranceMinor allows rounding differences from currency conversion.
     */
    public static function compare($a, $b, $toleranceMinor = 0)
    {
        $diff = self::toMinor($a) - self::toMinor($b);
        if (abs($diff) <= $toleranceMinor) {
            return 0;
        }
        return $diff < 0 ? -1 : 1;
    }

    public static function equals($a, $b, $toleranceMinor = 0)
    {
        return self::compare($a, $b, $toleranceMinor) === 0;
    }

    /** Relative difference between two amounts as a fraction (0.05 = 5%). */
    public static function relativeDiff($a, $b)
    {
        $a = (float) $a;
        $b = (float) $b;
        if ($b == 0.0) {
            return $a == 0.0 ? 0.0 : 1.0;
        }
        return abs($a - $b) / abs($b);
    }

    public static function normalizeCurrency($code)
    {
        return strtoupper(trim((string) $code));
    }
}
