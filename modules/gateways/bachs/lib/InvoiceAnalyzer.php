<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Decides whether an invoice can be turned into a Bachs auto-pay subscription.
 *
 * A Bachs subscription charges the same amount every cycle, so it is only
 * offered when this invoice is a "clean" renewal: every line is a recurring
 * service/addon/domain billed at exactly its recurring price on the same
 * cycle, with no setup fees, prorata, promotions, late fees, credit or
 * partial payments. Otherwise the customer pays once and can enable auto-pay
 * from the next renewal invoice.
 */
class InvoiceAnalyzer
{
    /**
     * @return array{eligible: bool, reason: string, cadence: ?array, items: array, tax_ratio: float, description: string}
     */
    public static function analyze($invoice)
    {
        $result = [
            'eligible' => false,
            'reason' => '',
            'cadence' => null,
            'items' => [],
            'tax_ratio' => 1.0,
            'description' => '',
        ];

        if ((float) $invoice->credit > 0 || (float) $invoice->amount_paid > 0) {
            $result['reason'] = 'Invoice has credit or partial payments applied';
            return $result;
        }

        $lines = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoice->id)->get();
        if (!count($lines)) {
            $result['reason'] = 'Invoice has no items';
            return $result;
        }

        $cadenceKey = null;
        $names = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $entry = self::recurringFor($line);
            if ($entry === null) {
                $result['reason'] = 'Invoice contains a non-recurring line (' . $line->type . ')';
                return $result;
            }
            if (!Money::equals($line->amount, $entry['recurring'])) {
                $result['reason'] = 'Line amount differs from the recurring price (setup fee, prorata or discount)';
                return $result;
            }
            $key = BillingCycle::key($entry['cadence']);
            if ($cadenceKey !== null && $cadenceKey !== $key) {
                $result['reason'] = 'Items renew on different billing cycles';
                return $result;
            }
            $cadenceKey = $key;
            $result['cadence'] = $entry['cadence'];
            $result['items'][] = ['type' => $entry['type'], 'relid' => (int) $line->relid];
            $names[] = $entry['name'];
            $subtotal += (float) $line->amount;
        }

        if ($subtotal <= 0) {
            $result['reason'] = 'Nothing to charge';
            return $result;
        }

        $result['tax_ratio'] = round((float) $invoice->total / $subtotal, 6);
        $result['description'] = mb_substr(implode(', ', array_unique($names)), 0, 200);
        $result['eligible'] = true;
        return $result;
    }

    /**
     * @return array|null ['type', 'cadence', 'recurring', 'name'] or null when not recurring
     */
    private static function recurringFor($line)
    {
        $relid = (int) $line->relid;
        if ($relid <= 0) {
            return null;
        }
        switch ($line->type) {
            case 'Hosting':
                $row = Capsule::table('tblhosting as h')
                    ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
                    ->where('h.id', $relid)
                    ->select('h.billingcycle', 'h.amount', 'h.domain', 'p.name')
                    ->first();
                if (!$row || !($cadence = BillingCycle::fromWhmcs($row->billingcycle))) {
                    return null;
                }
                return [
                    'type' => 'Hosting',
                    'cadence' => $cadence,
                    'recurring' => $row->amount,
                    'name' => trim($row->name . ($row->domain ? ' - ' . $row->domain : '')),
                ];

            case 'Addon':
                $row = Capsule::table('tblhostingaddons')->where('id', $relid)->first();
                if (!$row || !($cadence = BillingCycle::fromWhmcs($row->billingcycle))) {
                    return null;
                }
                return ['type' => 'Addon', 'cadence' => $cadence, 'recurring' => $row->recurring, 'name' => $row->name ?: 'Addon'];

            case 'Domain':
            case 'DomainRegister':
            case 'DomainTransfer':
                $row = Capsule::table('tbldomains')->where('id', $relid)->first();
                if (!$row || !($cadence = BillingCycle::fromDomainYears($row->registrationperiod))) {
                    return null;
                }
                return ['type' => 'Domain', 'cadence' => $cadence, 'recurring' => $row->recurringamount, 'name' => $row->domain];
        }
        return null;
    }
}
