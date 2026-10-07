<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Wrappers around WHMCS data and internal API calls.
 */
class Whmcs
{
    /** Invoice item types that map to a recurring WHMCS entity. */
    const ITEM_TABLES = [
        'Hosting' => 'tblhosting',
        'Addon' => 'tblhostingaddons',
        'Domain' => 'tbldomains',
        'DomainRegister' => 'tbldomains',
        'DomainTransfer' => 'tbldomains',
    ];

    /**
     * @return object|null invoice row with ->balance, ->currency_code, ->currency_id
     */
    public static function invoice($invoiceId)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$invoice) {
            return null;
        }
        $paid = (float) Capsule::table('tblaccounts')
            ->where('invoiceid', $invoice->id)
            ->sum(Capsule::raw('amountin - amountout'));
        $invoice->amount_paid = round($paid, 2);
        $invoice->balance = round((float) $invoice->total - $paid, 2);
        $currency = self::clientCurrency($invoice->userid);
        $invoice->currency_id = $currency->id;
        $invoice->currency_code = $currency->code;
        return $invoice;
    }

    public static function clientCurrency($clientId)
    {
        $currencyId = (int) Capsule::table('tblclients')->where('id', (int) $clientId)->value('currency');
        $currency = $currencyId ? Capsule::table('tblcurrencies')->where('id', $currencyId)->first() : null;
        if (!$currency) {
            $currency = Capsule::table('tblcurrencies')->where('default', 1)->first();
        }
        return $currency;
    }

    public static function currencyByCode($code)
    {
        return Capsule::table('tblcurrencies')->where('code', Money::normalizeCurrency($code))->first();
    }

    /**
     * Convert between two WHMCS currencies using the rates configured in
     * Setup > Payments > Currencies (rates are relative to the default currency).
     *
     * @return float|null null when either currency is not configured in WHMCS
     */
    public static function convert($amount, $fromCode, $toCode)
    {
        $fromCode = Money::normalizeCurrency($fromCode);
        $toCode = Money::normalizeCurrency($toCode);
        if ($fromCode === $toCode) {
            return round((float) $amount, 2);
        }
        $from = self::currencyByCode($fromCode);
        $to = self::currencyByCode($toCode);
        if (!$from || !$to || (float) $from->rate <= 0) {
            return null;
        }
        $base = (float) $amount / (float) $from->rate;
        return round($base * (float) $to->rate, 2);
    }

    public static function transactionExists($transId)
    {
        return Capsule::table('tblaccounts')
            ->where('gateway', BACHS_GATEWAY)
            ->where('transid', $transId)
            ->exists();
    }

    /**
     * Record a payment against an invoice through the WHMCS internal API.
     * WHMCS marks the invoice Paid (and runs provisioning/renewal hooks) when
     * the balance reaches zero; any overpayment becomes account credit.
     *
     * @throws \RuntimeException
     */
    public static function addInvoicePayment($invoiceId, $transId, $amount, $fees = 0.0, $date = null)
    {
        $params = [
            'invoiceid' => (int) $invoiceId,
            'transid' => $transId,
            'gateway' => BACHS_GATEWAY,
            'amount' => round((float) $amount, 2),
            'fees' => round((float) $fees, 2),
        ];
        if ($date) {
            $params['date'] = $date;
        }
        $result = localAPI('AddInvoicePayment', $params);
        if (!is_array($result) || (isset($result['result']) && $result['result'] !== 'success')) {
            throw new \RuntimeException('AddInvoicePayment failed: ' . (is_array($result) && isset($result['message']) ? $result['message'] : json_encode($result)));
        }
    }

    public static function addCredit($clientId, $amount, $description)
    {
        $result = localAPI('AddCredit', [
            'clientid' => (int) $clientId,
            'description' => $description,
            'amount' => round((float) $amount, 2),
        ]);
        if (!is_array($result) || (isset($result['result']) && $result['result'] !== 'success')) {
            throw new \RuntimeException('AddCredit failed: ' . (is_array($result) && isset($result['message']) ? $result['message'] : json_encode($result)));
        }
    }

    public static function client($clientId)
    {
        return Capsule::table('tblclients')->where('id', (int) $clientId)->first();
    }

    /**
     * Store the Bachs subscription ID on the WHMCS service so it shows in the
     * admin area and WHMCS calls bachs_cancelSubscription() on cancellation.
     */
    public static function setSubscriptionId($type, $relid, $subscriptionId)
    {
        if (!isset(self::ITEM_TABLES[$type])) {
            return;
        }
        $table = self::ITEM_TABLES[$type];
        if (Capsule::schema()->hasColumn($table, 'subscriptionid')) {
            Capsule::table($table)->where('id', (int) $relid)->update(['subscriptionid' => (string) $subscriptionId]);
        }
    }

    public static function clearSubscriptionId($subscriptionId)
    {
        foreach (array_unique(array_values(self::ITEM_TABLES)) as $table) {
            if (Capsule::schema()->hasColumn($table, 'subscriptionid')) {
                Capsule::table($table)->where('subscriptionid', $subscriptionId)->update(['subscriptionid' => '']);
            }
        }
    }

    /**
     * Unpaid invoices that bill any of the given items, oldest first.
     *
     * @param array $items [['type' => 'Hosting', 'relid' => 12], ...]
     * @return array of invoice ids
     */
    public static function unpaidInvoicesForItems(array $items, $clientId)
    {
        if (!$items) {
            return [];
        }
        $query = Capsule::table('tblinvoiceitems as ii')
            ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
            ->where('i.userid', (int) $clientId)
            ->where('i.status', 'Unpaid')
            ->where(function ($q) use ($items) {
                foreach ($items as $item) {
                    $types = self::equivalentTypes($item['type']);
                    $q->orWhere(function ($q2) use ($types, $item) {
                        $q2->whereIn('ii.type', $types)->where('ii.relid', (int) $item['relid']);
                    });
                }
            })
            ->orderBy('i.duedate', 'asc')
            ->orderBy('i.id', 'asc')
            ->distinct()
            ->pluck('i.id');
        return array_map('intval', is_array($query) ? $query : $query->all());
    }

    private static function equivalentTypes($type)
    {
        if (in_array($type, ['Domain', 'DomainRegister', 'DomainTransfer'], true)) {
            return ['Domain', 'DomainRegister', 'DomainTransfer'];
        }
        return [$type];
    }

    /** Current status of a recurring item (Active, Suspended, Cancelled, ...). */
    public static function itemStatus($type, $relid)
    {
        if (!isset(self::ITEM_TABLES[$type])) {
            return null;
        }
        $table = self::ITEM_TABLES[$type];
        $col = $table === 'tblhosting' ? 'domainstatus' : 'status';
        return Capsule::table($table)->where('id', (int) $relid)->value($col);
    }
}
