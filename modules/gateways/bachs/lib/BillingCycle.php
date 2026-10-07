<?php

namespace HarmonWeb\Bachs;

/**
 * Maps WHMCS billing cycles to Bachs subscription cadences
 * ({"interval": "day|week|month|year", "frequency": n}).
 */
class BillingCycle
{
    const MAP = [
        'monthly' => ['month', 1],
        'quarterly' => ['month', 3],
        'semi-annually' => ['month', 6],
        'semiannually' => ['month', 6],
        'annually' => ['year', 1],
        'biennially' => ['year', 2],
        'triennially' => ['year', 3],
    ];

    /**
     * @param string $whmcsCycle e.g. "Monthly", "Annually"
     * @return array|null ['interval' => 'month', 'frequency' => 1] or null when not recurring
     */
    public static function fromWhmcs($whmcsCycle)
    {
        $key = strtolower(trim((string) $whmcsCycle));
        if (!isset(self::MAP[$key])) {
            return null;
        }
        return ['interval' => self::MAP[$key][0], 'frequency' => self::MAP[$key][1]];
    }

    /** Domain registration periods are expressed in years. */
    public static function fromDomainYears($years)
    {
        $years = (int) $years;
        if ($years < 1 || $years > 10) {
            return null;
        }
        return ['interval' => 'year', 'frequency' => $years];
    }

    public static function key(array $cadence)
    {
        return $cadence['interval'] . ':' . (int) $cadence['frequency'];
    }

    public static function label(array $cadence)
    {
        $n = (int) $cadence['frequency'];
        $unit = $cadence['interval'];
        return $n === 1 ? ('every ' . $unit) : ('every ' . $n . ' ' . $unit . 's');
    }
}
