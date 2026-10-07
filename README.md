# Bachs Payment Gateway for WHMCS

A WHMCS payment gateway module for [Bachs](https://bachs.io), built for Harmonweb.

- **Hosted checkout:** cards, crypto, bank transfer and mobile money.
- **Multi-currency:** invoices are charged in the client's currency (NGN, USD, GHS, KES, …). WHMCS's *Convert To For Processing* setting is supported.
- **Card auto-pay:** the customer saves a card through a Bachs subscription, and Bachs charges it automatically on each renewal.
- **Signed webhooks:** HMAC verification, replay protection and idempotent processing.
- **Payment validation cron:** finds payments that succeeded at Bachs but were never applied in WHMCS, and fixes the invoice.
- Refunds (full and partial), admin "re-check payment" button, and a client-area page for managing auto-pay.

Requires WHMCS 8.x and PHP 7.4 – 8.3, with cURL and MySQL/MariaDB.

---

## Installation

1. Upload the files into your WHMCS root, keeping the paths:

   ```
   modules/gateways/bachs.php
   modules/gateways/bachs/            (lib/, pay.php, return.php, manage.php, admin.php, cron.php)
   modules/gateways/callback/bachs.php
   includes/hooks/bachs.php
   ```

   Do **not** upload `tests/`.

2. **Setup → Payments → Payment Gateways → All Payment Gateways → Bachs**. Activate it.
3. Enter your keys from the Bachs dashboard:
   - *Live Secret Key* (`sk_live_…`) and *Sandbox Secret Key* (`sk_sandbox_…`)
   - *Webhook Signing Secret* (`whsec_…`) for each environment (see below)
4. Pick the payment methods to accept, enable or disable auto-pay, and save. On save, the module checks the API key against Bachs and creates its database tables (`mod_bachs_*`). It refuses to save without a webhook secret.

### Webhook

URL: `https://YOUR-WHMCS/modules/gateways/callback/bachs.php`

Either create the endpoint in the Bachs dashboard, or let the module register it for you:

```bash
php modules/gateways/bachs/cron.php register-webhook
```

This prints the signing secret **once**. Paste it into the gateway settings.

Subscribe the endpoint to these events: `checkout.completed`, `checkout.expired`, `collection.succeeded`, `collection.failed`, `collection.underpaid`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `refund.paid`, `refund.failed`.

### Cron

Reconciliation runs automatically after every WHMCS cron (`AfterCronJob` hook), so nothing extra is needed as long as the WHMCS cron is running. For a tighter or independent schedule you can add:

```cron
*/10 * * * * php -q /path/to/whmcs/modules/gateways/bachs/cron.php reconcile
```

Overlapping runs are prevented with a MySQL advisory lock.

---

## How it works

### One-time payment

1. The invoice page shows **Pay Now**. This is a POST form carrying an HMAC token tied to that invoice, so nobody can create checkout sessions for arbitrary invoices.
2. `pay.php` creates a Bachs checkout session. It reuses an open session for the same invoice and amount rather than creating a new one, and caps creation at 15 per invoice per hour. The customer is then redirected to Bachs.
3. A payment is confirmed in three independent ways. Whichever runs first applies it; the others become no-ops:
   - **Return page** (`return.php`): verifies right away, so the customer usually lands on a Paid invoice.
   - **Webhook**: verified, de-duplicated and processed.
   - **Cron**: re-checks open checkouts and sweeps recent Bachs payments.

**Security model:** a webhook body, redirect or query string is never trusted for payment state. Every path re-fetches the checkout from the Bachs API, then checks four things before touching WHMCS:
- reference, invoice metadata, amount and currency match what was requested;
- the amount paid is not short (crypto underpayments are caught);
- the Bachs payment ID can be claimed atomically (a unique key in `mod_bachs_payments`);
- no WHMCS transaction with that ID exists yet.

Webhook, return page and cron can race without ever double-crediting an invoice.

If a payment arrives for an invoice that was meanwhile paid another way or cancelled, the money is added to the client's **credit balance** and flagged in the activity log.

### Auto-pay (saved card)

Bachs has no API for charging a stored card on demand. Recurring billing works through **Bachs subscriptions**: the customer pays the first cycle through a checkout, Bachs saves the card, and Bachs then charges it every cycle by itself. The module maps WHMCS to that model like this:

- **Pay & enable auto-renew (card)** appears next to Pay Now when the invoice is a clean renewal: every line is a recurring service, addon or domain billed at exactly its recurring price, on one shared billing cycle, with no setup fee, prorata, promo, late fee, credit or partial payment. This is required because a Bachs subscription charges the same amount every cycle. On a first order with a setup fee, the customer pays normally and can enable auto-pay from the next renewal invoice.
- WHMCS billing cycles map to Bachs cadences: Monthly → 1 month, Quarterly → 3 months, Semi-Annually → 6 months, Annually / Biennially / Triennially → 1 / 2 / 3 years. Domains map to their registration period.
- After the first payment, the Bachs subscription ID is saved on the service (`tblhosting.subscriptionid`, and the addon/domain equivalents). It shows in the admin area, and WHMCS calls `bachs_cancelSubscription` when the service is cancelled or terminated or when an admin clicks *Cancel Subscription*.
- **Renewals:** when Bachs charges the card, the webhook (or the cron sweep) finds the oldest unpaid WHMCS invoice for the subscribed services and applies the payment. WHMCS then marks it paid and runs the normal renewal and unsuspension logic.
  - If Bachs charges *before* WHMCS has generated the renewal invoice, the payment is parked and applied once the invoice appears.
  - If no matching invoice shows up within *Credit Unmatched Renewals After* days (default 3), the payment is added to the client's credit, so money never disappears.
- **Price changes:** with *Sync Auto-Pay Price* on, the cron moves the subscription to the new price for the next cycle (no proration) when the service's recurring amount changes. This covers upgrades, price increases and exchange-rate drift.
- When every service a subscription pays for is Cancelled or Terminated, the cron cancels the subscription at Bachs.
- **Currency:** Bachs subscriptions are **USD only** at the moment. For clients billed in USD this just works. For clients billed in NGN or other currencies, enable *Auto-Pay for non-USD Invoices*. The subscription is then priced in USD at your WHMCS exchange rate, and each renewal charge settles the client's invoice in full. USD must exist under *Setup → Payments → Currencies* with a current rate.
- **Clients** manage auto-pay at **Billing → Automatic Payments** (`modules/gateways/bachs/manage.php`). They can update cards in the Bachs customer portal or turn auto-pay off. Service pages show an "Auto-pay is on" banner.

### Payment validation cron

Each run does the following:

1. Re-checks unfinished checkouts. Checks are frequent in the first hour, then back off to every 30 minutes, then every 3 hours, until the lookback window ends (default 7 days).
2. Re-applies payments that errored mid-way, or that are recorded as applied but have no WHMCS transaction.
3. Retries parked renewal payments, and credits them after the grace period.
4. Sweeps the latest Bachs payments (every 15 minutes) and applies any of ours that WHMCS doesn't know about. This is the catch-all for lost webhooks, including subscription renewals.
5. Links subscription IDs that never arrived, and syncs subscription status, price and cancellations twice a day.

CLI tools:

```bash
php modules/gateways/bachs/cron.php reconcile          # run now, verbose
php modules/gateways/bachs/cron.php status             # last run, pending/error counts
php modules/gateways/bachs/cron.php verify 1234        # re-verify invoice #1234
```

Admins also get a **Re-check Bachs payment** button on any invoice that has Bachs checkouts.

### Refunds

Use the normal *Refund* action on the transaction. A full refund omits the amount, so Bachs refunds the remaining balance. A partial refund is converted to the charge currency when the payment was made in a different currency. Crypto refunds may need a destination address, which Bachs handles in its dashboard.

---

## Where to look when something is wrong

| Where | What |
| --- | --- |
| Billing → Gateway Log | Every payment outcome: Success, Credited, Unmatched, Verification Failed, Invalid Signature, Renewal Failed |
| Utilities → Logs → Activity Log | `[Bachs]` operational events: auto-pay linked or cancelled, repricing, reconciliation summaries |
| Utilities → Logs → Module Log | Raw API traffic when *Debug Logging* is on. API keys, signatures and portal URLs are masked. |
| `cron.php status` | Open, errored and **mismatch** checkouts (mismatches need manual review), unmatched payments, failed webhooks |

**Statuses that need attention:**
- `mismatch` on a checkout: paid amount or currency differs from what was requested. Never auto-applied.
- `unmatched` on a payment: renewal with no invoice yet. Auto-credited after the grace period.

---

## Security notes

- API keys and webhook secrets are stored in WHMCS's encrypted gateway settings and are never logged.
- Live mode refuses `sk_sandbox_` keys and sandbox mode refuses live keys.
- Webhooks must pass an HMAC-SHA256 signature over `"{timestamp}.{raw_body}"` with a ±5-minute window. They are de-duplicated by event ID and capped at 1 MB. Transient failures return HTTP 500 so Bachs retries; bad signatures return 400.
- All API calls use TLS with peer and host verification, HTTPS only, no redirects, timeouts, retry with backoff on 429/5xx, and an `Idempotency-Key` on every write.
- Customer-facing endpoints:
  - `pay.php`: POST-only with an HMAC form token; checks invoice ownership for logged-in clients; rate-limited.
  - `manage.php`: login plus a CSRF token, and only acts on the logged-in client's own subscriptions.
  - `admin.php`: admin session plus a per-admin HMAC token.
- `lib/` is blocked from web access (`.htaccess`). Entry points send `no-store` and anti-framing headers.

---

## Tests

The `tests/` folder runs the module against a fake WHMCS (SQLite, stubbed `localAPI`) and a fake Bachs API. It covers 31 scenarios, including:
- webhook signatures, replays and forged events;
- amount mismatch and crypto underpayment;
- lost webhooks fixed by the cron;
- webhook, return page and cron racing each other;
- auto-pay signup, renewal, early renewal, crediting and repricing;
- NGN → USD auto-pay, refunds, and secret masking.

```bash
cd tests && composer install && php run.php
```

---

## Notes on the Bachs API

This module was built against the Bachs v1 API as described by the official Bachs SDK, which is generated from `docs.bachs.io/docs/openapi/openapi.json`. The endpoints it uses are:
- `/v1/checkout-sessions`, `/v1/payments`, `/v1/customers` (+ `portal-sessions`)
- `/v1/products`, `/v1/subscriptions`, `/v1/refunds`
- `/v1/currencies/supported`, `/v1/webhooks/endpoints`

The webhook parser accepts several payload layouts (`data`, `object`, `entity_type`/`entity_id`). It only uses the IDs it finds there and always reads payment state from the API.

Before going live, run one sandbox payment of each kind and confirm the Gateway Log shows `Success`:
- a one-time card payment;
- a one-time crypto payment;
- an auto-pay signup.
