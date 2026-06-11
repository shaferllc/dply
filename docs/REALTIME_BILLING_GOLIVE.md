# Realtime billing — go-live checklist

Managed Realtime (broadcasting) apps are billed **per active app, priced by
connection tier** (Starter $15 / Growth $49 / Scale $149 per month). The billing
code, the org-level management dashboard, and the tier-change flow are all wired
and tested. What remains for production is operational: provision **live-mode**
Stripe prices and set them in the prod environment.

> Until the live tier prices below are set in prod, active apps **will not bill**
> (the syncer silently emits no line for a tier whose price id is blank).

## How billing flows (for reference)

- Each active `RealtimeApp` is grouped by `tierSlug()` in
  `OrganizationBillingStateComputer` → `realtimeTierQuantities`.
- `DesiredBillingState` prices each tier from `config('realtime.tiers')`.
- `StripeSubscriptionSyncer` emits one subscription line per tier in use and
  strips the retired flat line.
- `RealtimeAppBillingObserver` dispatches `SyncOrganizationBillingJob` on app
  create / tier-change / delete, so changes bill immediately (no nightly lag).

## 1. Provision live-mode Stripe prices

Run against the **live** Stripe secret key (not test):

```bash
# Dry run first — shows every product/price it will create.
php artisan dply:billing:provision-stripe --dry-run

php artisan dply:billing:provision-stripe
```

It loops `config('realtime.tiers')` and creates a monthly + yearly price per
tier under the `dply Realtime app` product, then prints copy-paste `.env` lines:

```
STRIPE_PRICE_STANDARD_REALTIME_STARTER=price_...
STRIPE_PRICE_STANDARD_REALTIME_STARTER_YEARLY=price_...
STRIPE_PRICE_STANDARD_REALTIME_GROWTH=price_...
STRIPE_PRICE_STANDARD_REALTIME_GROWTH_YEARLY=price_...
STRIPE_PRICE_STANDARD_REALTIME_SCALE=price_...
STRIPE_PRICE_STANDARD_REALTIME_SCALE_YEARLY=price_...
```

Re-running is a no-op once the roles exist (keyed by `metadata.dply_role`).

## 2. Set them in prod env (web + workers)

The six vars above must be present in prod `.env` on the **app box and every
worker box** (the billing sync runs on a worker). Per the env source-of-truth
rules, the canonical place is the DB-backed env; mirror to `shared/.env` as the
deploy writes it. After editing:

```bash
php artisan config:cache   # required after any .env edit (see prod queue routing)
```

The legacy flat `STRIPE_PRICE_STANDARD_REALTIME[_YEARLY]` vars can stay (the
syncer strips the flat line); they are no longer the billing source.

## 3. Verify

```bash
php artisan dply:realtime:doctor          # checks per-tier prices, flag, CF, worker
php artisan dply:realtime:doctor --probe  # also does a live KV write/read/delete
```

`stripe_tier_prices` must read **set (starter, growth, scale)**. If any tier is
missing, the doctor names the exact env var.

Then reconcile existing orgs so any already-provisioned apps pick up the live
line:

```bash
php artisan dply:billing:sync-all --dry-run
php artisan dply:billing:sync-all
```

## 4. Smoke test the customer path

1. On a site, Resources → Configure broadcasting → dply realtime → pick a tier →
   confirm the charge → provision.
2. Org → **Realtime** dashboard shows the app, its tier, and the monthly total.
3. Change the tier (upgrade requires re-consent); confirm the Stripe subscription
   line moves and the bill updates.
4. Delete; confirm the line is removed and the relay app is torn down.

## Local / staging testing

In `local` and `testing` environments `DPLY_FAKE_REALTIME` defaults from
`DPLY_FAKE_EDGE`, so provisioning uses the cache-backed fake relay — no
Cloudflare calls. The automated suite covers the billing math, tier change,
observer resync, and dashboard auth:

```bash
./vendor/bin/pest tests/Unit/Services/Billing/RealtimeTierBillingTest.php \
                  tests/Feature/Livewire/Organizations/RealtimeDashboardTest.php
```

> Note: prod local dev points `DPLY_REALTIME_HOST` at the real branded relay and
> runs `DPLY_FAKE_REALTIME=false` so the control plane dogfoods broadcasting. To
> exercise the dashboard against the fake relay instead, set
> `DPLY_FAKE_REALTIME=true` temporarily (this also makes local app broadcasting
> go through the cache).
