<?php

namespace HarmonWeb\Bachs;

use WHMCS\Database\Capsule;

/**
 * Module-owned tables. Created on first use; never dropped automatically so
 * payment history survives deactivating the gateway.
 */
class Schema
{
    const VERSION = 1;

    const T_CHECKOUTS = 'mod_bachs_checkouts';
    const T_PAYMENTS = 'mod_bachs_payments';
    const T_EVENTS = 'mod_bachs_webhook_events';
    const T_SUBSCRIPTIONS = 'mod_bachs_subscriptions';
    const T_CUSTOMERS = 'mod_bachs_customers';
    const T_PRODUCTS = 'mod_bachs_products';
    const T_KV = 'mod_bachs_kv';

    private static $checked = false;

    public static function ensure()
    {
        if (self::$checked) {
            return;
        }
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::T_KV)) {
            $schema->create(self::T_KV, function ($t) {
                $t->string('k', 100)->primary();
                $t->text('v')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        $installed = (int) Capsule::table(self::T_KV)->where('k', 'schema_version')->value('v');
        if ($installed >= self::VERSION) {
            self::$checked = true;
            return;
        }

        // One row per hosted checkout session we create (one-time or auto-pay signup).
        if (!$schema->hasTable(self::T_CHECKOUTS)) {
            $schema->create(self::T_CHECKOUTS, function ($t) {
                $t->increments('id');
                $t->string('checkout_id', 100)->nullable()->unique();
                $t->string('reference', 100)->unique();
                $t->integer('invoice_id')->index();
                $t->integer('client_id')->index();
                $t->string('mode', 20)->default('one_time'); // one_time | subscription
                $t->decimal('invoice_amount', 16, 2);         // in invoice currency
                $t->string('invoice_currency', 10);
                $t->string('gateway_amount', 32);              // as sent to Bachs
                $t->string('gateway_currency', 10);
                $t->text('checkout_url')->nullable();
                $t->string('status', 20)->default('open')->index(); // open|completed|expired|cancelled|failed|underpaid|error|mismatch
                $t->string('payment_id', 100)->nullable()->index();
                $t->string('subscription_id', 100)->nullable()->index();
                $t->text('subscription_plan')->nullable();     // JSON snapshot of auto-pay plan
                $t->boolean('sandbox')->default(false);
                $t->integer('check_count')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('last_checked_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        // One row per Bachs payment we have applied (or tried to). The unique
        // payment_id is the atomic claim that prevents double-crediting when the
        // webhook, return page and cron race each other.
        if (!$schema->hasTable(self::T_PAYMENTS)) {
            $schema->create(self::T_PAYMENTS, function ($t) {
                $t->increments('id');
                $t->string('payment_id', 100)->unique();
                $t->string('kind', 20);                        // checkout | renewal
                $t->integer('invoice_id')->nullable()->index();
                $t->integer('client_id')->nullable()->index();
                $t->string('subscription_id', 100)->nullable()->index();
                $t->string('amount', 32);
                $t->string('currency', 10);
                $t->decimal('applied_amount', 16, 2)->nullable();
                $t->string('status', 20)->index();             // processing|applied|unmatched|credited|duplicate|error
                $t->text('note')->nullable();
                $t->integer('attempts')->default(0);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_EVENTS)) {
            $schema->create(self::T_EVENTS, function ($t) {
                $t->increments('id');
                $t->string('event_id', 120)->unique();
                $t->string('event_type', 80)->index();
                $t->string('status', 20)->default('received'); // received|processed|failed|ignored
                $t->text('error')->nullable();
                $t->integer('attempts')->default(0);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_SUBSCRIPTIONS)) {
            $schema->create(self::T_SUBSCRIPTIONS, function ($t) {
                $t->increments('id');
                $t->string('subscription_id', 100)->unique();
                $t->integer('client_id')->index();
                $t->string('customer_id', 100)->nullable();
                $t->string('product_id', 100)->nullable();
                $t->text('items');                             // JSON [{type, relid}]
                $t->string('amount', 32);                      // recurring amount in Bachs currency
                $t->string('currency', 10);
                $t->string('interval', 10);
                $t->integer('frequency');
                $t->decimal('invoice_amount', 16, 2);          // recurring amount in invoice currency
                $t->string('invoice_currency', 10);
                $t->decimal('tax_ratio', 10, 6)->default(1);
                $t->string('status', 20)->index();
                $t->timestamp('next_billed_at')->nullable();
                $t->integer('origin_invoice_id')->nullable();
                $t->boolean('sandbox')->default(false);
                $t->timestamp('last_synced_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_CUSTOMERS)) {
            $schema->create(self::T_CUSTOMERS, function ($t) {
                $t->increments('id');
                $t->integer('client_id');
                $t->boolean('sandbox')->default(false);
                $t->string('customer_id', 100);
                $t->timestamp('created_at')->nullable();
                $t->unique(['client_id', 'sandbox']);
            });
        }

        if (!$schema->hasTable(self::T_PRODUCTS)) {
            $schema->create(self::T_PRODUCTS, function ($t) {
                $t->increments('id');
                $t->string('cache_key', 190)->unique();
                $t->string('product_id', 100);
                $t->timestamp('created_at')->nullable();
            });
        }

        Capsule::table(self::T_KV)->updateOrInsert(
            ['k' => 'schema_version'],
            ['v' => (string) self::VERSION, 'updated_at' => date('Y-m-d H:i:s')]
        );
        self::$checked = true;
    }
}
