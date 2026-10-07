<?php
/**
 * Minimal fake WHMCS runtime for exercising the module end to end:
 * SQLite database behind WHMCS\Database\Capsule, and stubs for localAPI,
 * logTransaction, logActivity, logModuleCall and getGatewayVariables.
 */

namespace {
    require __DIR__ . '/vendor/autoload.php';
}

namespace WHMCS\Database {
    class Capsule extends \Illuminate\Database\Capsule\Manager
    {
    }
}

namespace WHMCS\Config {
    class Setting
    {
        public static $values = ['SystemURL' => 'https://billing.harmonweb.test/', 'CompanyName' => 'Harmonweb'];

        public static function getValue($k)
        {
            return self::$values[$k] ?? null;
        }
    }
}

namespace WHMCS\Exception\Module {
    class InvalidConfiguration extends \Exception
    {
    }
}

namespace {
    use WHMCS\Database\Capsule;

    define('WHMCS', true);
    define('ROOTDIR', __DIR__);

    $GLOBALS['__log'] = ['transactions' => [], 'activity' => [], 'module' => []];
    $GLOBALS['__gateway'] = [];

    function getGatewayVariables($name)
    {
        return $GLOBALS['__gateway'];
    }

    function logTransaction($gateway, $data, $status)
    {
        $GLOBALS['__log']['transactions'][] = ['status' => $status, 'data' => $data];
    }

    function logActivity($msg, $userId = 0)
    {
        $GLOBALS['__log']['activity'][] = $msg;
    }

    function logModuleCall($module, $action, $req, $resp, $processed, $replace)
    {
        $GLOBALS['__log']['module'][] = [$action, $req, $resp];
    }

    function localAPI($command, $params)
    {
        if ($command === 'AddInvoicePayment') {
            $invoice = Capsule::table('tblinvoices')->where('id', $params['invoiceid'])->first();
            if (!$invoice) {
                return ['result' => 'error', 'message' => 'Invoice ID Not Found'];
            }
            Capsule::table('tblaccounts')->insert([
                'userid' => $invoice->userid,
                'invoiceid' => $invoice->id,
                'gateway' => $params['gateway'],
                'transid' => $params['transid'],
                'amountin' => $params['amount'],
                'amountout' => 0,
                'fees' => $params['fees'] ?? 0,
            ]);
            $paid = Capsule::table('tblaccounts')->where('invoiceid', $invoice->id)->sum('amountin');
            if ($paid + 0.001 >= $invoice->total) {
                Capsule::table('tblinvoices')->where('id', $invoice->id)->update(['status' => 'Paid']);
                if ($paid - $invoice->total > 0.001) {
                    Capsule::table('tblclients')->where('id', $invoice->userid)->increment('credit', $paid - $invoice->total);
                }
            }
            return ['result' => 'success'];
        }
        if ($command === 'AddCredit') {
            Capsule::table('tblclients')->where('id', $params['clientid'])->increment('credit', $params['amount']);
            return ['result' => 'success'];
        }
        return ['result' => 'error', 'message' => 'unsupported ' . $command];
    }

    function bachs_test_reset_db()
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $pdo = Capsule::connection()->getPdo();
        $pdo->sqliteCreateFunction('GET_LOCK', function ($name, $timeout) {
            return 1;
        }, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', function ($name) {
            return 1;
        }, 1);

        $s = Capsule::schema();
        $s->create('tblcurrencies', function ($t) {
            $t->increments('id');
            $t->string('code');
            $t->decimal('rate', 10, 5);
            $t->integer('default')->default(0);
        });
        $s->create('tblclients', function ($t) {
            $t->increments('id');
            $t->string('firstname');
            $t->string('lastname');
            $t->string('email');
            $t->integer('currency');
            $t->decimal('credit', 10, 2)->default(0);
        });
        $s->create('tblinvoices', function ($t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('status');
            $t->decimal('subtotal', 10, 2);
            $t->decimal('total', 10, 2);
            $t->decimal('credit', 10, 2)->default(0);
            $t->date('duedate')->nullable();
            $t->string('paymentmethod')->default('bachs');
        });
        $s->create('tblinvoiceitems', function ($t) {
            $t->increments('id');
            $t->integer('invoiceid');
            $t->string('type');
            $t->integer('relid');
            $t->string('description')->default('');
            $t->decimal('amount', 10, 2);
        });
        $s->create('tblaccounts', function ($t) {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('invoiceid');
            $t->string('gateway');
            $t->string('transid');
            $t->decimal('amountin', 10, 2);
            $t->decimal('amountout', 10, 2)->default(0);
            $t->decimal('fees', 10, 2)->default(0);
        });
        $s->create('tblproducts', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $s->create('tblhosting', function ($t) {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('packageid');
            $t->string('domain')->default('');
            $t->string('billingcycle');
            $t->decimal('amount', 10, 2);
            $t->string('domainstatus')->default('Active');
            $t->string('subscriptionid')->default('');
        });
        $s->create('tblhostingaddons', function ($t) {
            $t->increments('id');
            $t->integer('hostingid');
            $t->string('name')->default('');
            $t->string('billingcycle');
            $t->decimal('recurring', 10, 2);
            $t->string('status')->default('Active');
            $t->string('subscriptionid')->default('');
        });
        $s->create('tbldomains', function ($t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('domain');
            $t->integer('registrationperiod');
            $t->decimal('recurringamount', 10, 2);
            $t->string('status')->default('Active');
            $t->string('subscriptionid')->default('');
        });

        Capsule::table('tblcurrencies')->insert([
            ['id' => 1, 'code' => 'USD', 'rate' => 1, 'default' => 1],
            ['id' => 2, 'code' => 'NGN', 'rate' => 1500, 'default' => 0],
        ]);
        Capsule::table('tblclients')->insert([
            ['id' => 1, 'firstname' => 'Ada', 'lastname' => 'Obi', 'email' => 'ngn@example.com', 'currency' => 2],
            ['id' => 2, 'firstname' => 'Sam', 'lastname' => 'Doe', 'email' => 'usd@example.com', 'currency' => 1],
        ]);
        Capsule::table('tblproducts')->insert(['id' => 1, 'name' => 'Business Hosting']);

        // Reset module static caches between tests.
        $r = new \ReflectionProperty(\HarmonWeb\Bachs\Schema::class, 'checked');
        $r->setAccessible(true);
        $r->setValue(null, false);
        \HarmonWeb\Bachs\Schema::ensure();

        $GLOBALS['__log'] = ['transactions' => [], 'activity' => [], 'module' => []];
    }

    function bachs_test_config(array $overrides = [])
    {
        $params = $overrides + [
            'type' => 'Invoices',
            'name' => 'bachs',
            'systemurl' => 'https://billing.harmonweb.test/',
            'liveSecretKey' => 'sk_live_test_123456789',
            'liveWebhookSecret' => 'whsec_live_abcdef',
            'sandboxSecretKey' => 'sk_sandbox_123456789',
            'sandboxWebhookSecret' => 'whsec_sandbox_abcdef',
            'testMode' => '',
            'allowCard' => 'on',
            'allowCrypto' => 'on',
            'allowBankTransfer' => 'on',
            'allowMobileMoney' => '',
            'enableAutopay' => 'on',
            'autopayConvert' => '',
            'priceSync' => 'on',
            'priceSyncThreshold' => '1',
            'checkoutExpiry' => '60',
            'reconcileDays' => '7',
            'unmatchedCreditDays' => '3',
            'debugLogging' => 'on',
            'convertto' => '',
        ];
        $GLOBALS['__gateway'] = $params;
        return \HarmonWeb\Bachs\Config::fromParams($params);
    }

    function bachs_test_invoice($clientId, array $items, $status = 'Unpaid')
    {
        $subtotal = array_sum(array_column($items, 'amount'));
        $id = Capsule::table('tblinvoices')->insertGetId([
            'userid' => $clientId,
            'status' => $status,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'duedate' => date('Y-m-d'),
        ]);
        foreach ($items as $item) {
            Capsule::table('tblinvoiceitems')->insert($item + ['invoiceid' => $id, 'relid' => 0]);
        }
        return $id;
    }

    require_once __DIR__ . '/../modules/gateways/bachs/lib/bootstrap.php';
}
