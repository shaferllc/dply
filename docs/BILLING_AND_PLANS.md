# Billing & plans

How **Standard** subscriptions, the 14-day trial, and **Enterprise** deals fit together in dply.

## The Standard plan

dply runs on one usage-based plan: **$15/mo organization base + a per-server fee that scales by detected server size.**

| Tier | Spec (auto-detected) | Per-server fee |
|------|----------------------|----------------|
| XS | ≤1 vCPU, ≤2 GB | $2/mo |
| S | 2 vCPU, ≤4 GB | $5/mo |
| M | ≤4 vCPU, ≤8 GB | $10/mo |
| L | ≤8 vCPU, ≤16 GB | $20/mo |
| XL | Above L | $40/mo |

Per-server fees are **capped at $40** — you're never charged more than the XL rate per box, no matter how large.

**dply-managed products** (Cloud, Edge, Serverless) bill separately as flat per-app fees — not as VM tiers. BYO servers you SSH into use the tier table below.

| Product | Unit | Default fee |
|---------|------|-------------|
| dply Cloud | per live app | $5/mo |
| dply Edge | per live site | $2/mo platform fee |
| dply Edge delivery (managed mode) | metered usage | pass-through + margin (see below) |

**Delivery usage billing applies to managed (`dply_edge`) sites only.** BYO Cloudflare (`org_cloudflare`) sites publish into the customer's account — they pay Cloudflare directly for Workers, R2, and bandwidth; dply does not meter that usage today.
| Serverless | per code function | $2/mo |
| BYO server | per VM (XS–XL) | $2–$40/mo |

dply prices its own work, not your cloud invoice — the **same BYO server fee applies whether you run on $5 Hetzner boxes or $500 AWS instances**.

### dply Edge — platform fee + delivery usage

Edge uses a **two-part model** so dply can pass Cloudflare delivery costs through with margin instead of absorbing them in the flat site fee alone:

| Component | What it covers | Default |
|-----------|----------------|---------|
| **Platform fee** | Builds, deploys, previews, console management | **$2/mo per live production site** |
| **Delivery usage** | HTTP requests, bandwidth egress, R2 storage/ops beyond per-site included allowances | **Metered monthly** (see unit rates below) |

**Default included allowances per live Edge site (each month):**

- 1M HTTP requests
- 10 GB egress
- 1 GB R2 storage

Usage above those thresholds bills at customer-facing unit rates (configured in `config/edge.php`, embed ~25% markup over Cloudflare list pricing by default):

| Meter | Default rate |
|-------|----------------|
| Requests | $0.50 / million |
| Bandwidth egress | $0.05 / GB |
| R2 storage | $0.03 / GB-month |

Enable usage billing per install:

```env
DPLY_EDGE_USAGE_BILLING_ENABLED=true
```

Snapshots are collected daily (`php artisan dply:edge:collect-usage`, scheduled at 02:00 UTC). When Cloudflare Analytics credentials are configured (`DPLY_EDGE_CF_*`), the collector pulls zone HTTP metrics via GraphQL; otherwise it writes **placeholder zero snapshots** so billing hooks stay wired — operators can import manual rows or reconcile from the monthly Cloudflare invoice until full automation lands.

Stripe sync adds a **monthly** `dply Edge delivery usage` line item (quantity = estimated cents) for monthly subscriptions. Yearly subscribers see the usage estimate in-product; Stripe metered sync for yearly plans is a follow-up.

## Annual

Same Standard plan, **20% off** when paid yearly. The toggle on the pricing page (and in Billing → Subscribe) sets the interval. Every line item — base + every per-server tier — is billed at the chosen interval; Stripe Checkout requires uniform intervals per subscription.

| Profile | Monthly | Yearly (20% off) |
|---|---|---|
| Base only | $15/mo | $144/yr |
| Base + 1 M server | $25/mo | $240/yr |
| Base + 5 M servers | $65/mo | $624/yr |

## Trial

Every new organization gets a **14-day trial**:

- No credit card required.
- Full product — connect your real fleet, deploy real apps, run real workloads.
- At day 15, if no payment method is added, **deploys and on-demand scheduler runs are paused** (the soft pause). Existing servers and sites keep running on your provider; the dply UI stays usable so a card-add unfreezes everything immediately.
- At day 45 (soft pause + 30 days), the agent telemetry endpoint **stops accepting** new metrics from the organization's servers (the hard pause). Your config is preserved; adding a payment method restores service.

Trial length and the soft-pause window are configurable per install:

```env
SUBSCRIPTION_TRIAL_DAYS=14
SUBSCRIPTION_SOFT_PAUSE_DAYS=30
```

## Enterprise

For larger fleets, procurement requirements, or custom contracts. Talk to sales — the Enterprise subscription is created manually in Stripe against a negotiated price.

What "Enterprise" generally adds:
- Volume pricing on per-server fees.
- Custom contract / MSA.
- SSO, audit log access, dedicated support, rollout planning.

## Stripe integration

Billing flows through **Laravel Cashier + Stripe Checkout**:
- Subscription creation goes through a Stripe-hosted Checkout session.
- Each tier has both a monthly and a yearly Stripe Price; the creator picks the matching set when building the subscription's line items.
- Quantity reconciliation between dply's server fleet and Stripe's subscription line items runs continuously: event-driven on server lifecycle changes (status→ready, status→disconnected, delete) and via a nightly safety sweep (`php artisan dply:billing:sync-all`).
- Webhooks update the local subscription state and dispatch a sync job to catch any drift.

## Provisioning Stripe

Run once per Stripe environment (test mode, then live):

```bash
php artisan dply:billing:provision-stripe --dry-run   # preview
php artisan dply:billing:provision-stripe              # create
```

The command is idempotent — it looks objects up by `metadata.dply_role` before creating, so re-running picks up partial failures and is a no-op once everything exists. The final output is a block of env vars to paste into `.env`.

## Required environment

```env
STRIPE_KEY=pk_...
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Standard plan — base prices (monthly + yearly)
STRIPE_PRICE_STANDARD_BASE_MONTHLY=price_...
STRIPE_PRICE_STANDARD_BASE_YEARLY=price_...

# Per-server tiers — monthly variants
STRIPE_PRICE_STANDARD_TIER_XS=price_...
STRIPE_PRICE_STANDARD_TIER_S=price_...
STRIPE_PRICE_STANDARD_TIER_M=price_...
STRIPE_PRICE_STANDARD_TIER_L=price_...
STRIPE_PRICE_STANDARD_TIER_XL=price_...

# Per-server tiers — yearly variants (required so yearly Checkout works)
STRIPE_PRICE_STANDARD_TIER_XS_YEARLY=price_...
STRIPE_PRICE_STANDARD_TIER_S_YEARLY=price_...
STRIPE_PRICE_STANDARD_TIER_M_YEARLY=price_...
STRIPE_PRICE_STANDARD_TIER_L_YEARLY=price_...
STRIPE_PRICE_STANDARD_TIER_XL_YEARLY=price_...

# dply-managed products — flat per unit (monthly + yearly)
STRIPE_PRICE_STANDARD_SERVERLESS=price_...
STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY=price_...
STRIPE_PRICE_STANDARD_CLOUD=price_...
STRIPE_PRICE_STANDARD_CLOUD_YEARLY=price_...
STRIPE_PRICE_STANDARD_EDGE=price_...
STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_...
STRIPE_PRICE_STANDARD_EDGE_USAGE=price_...

# Edge usage billing (optional — pass-through delivery costs)
# DPLY_EDGE_USAGE_BILLING_ENABLED=true
# DPLY_EDGE_USAGE_MARKUP_PERCENT=25
# DPLY_EDGE_USAGE_REQUESTS_CENTS_PER_MILLION=50
# DPLY_EDGE_USAGE_EGRESS_CENTS_PER_GB=5
# DPLY_EDGE_USAGE_R2_STORAGE_CENTS_PER_GB_MONTH=3
# DPLY_EDGE_USAGE_INCLUDED_REQUESTS_PER_SITE=1000000
# DPLY_EDGE_USAGE_INCLUDED_EGRESS_GB_PER_SITE=10
# DPLY_EDGE_USAGE_INCLUDED_R2_STORAGE_GB_PER_SITE=1

# Enterprise — single negotiated price, applied manually
STRIPE_PRICE_ENTERPRISE=price_...
```

## Tax and VAT (EU)

Where EU **VAT** collection applies, your organization can maintain valid tax identifiers. Validation may use external services (for example **VIES**) when enabled — timeouts and country rules are configured on the server. Invoice presentation and tax lines follow Stripe and your organization's details at checkout.

## API tokens and plan gating

Some installs gate **API token creation** behind an active paid plan. Controlled by:

```env
DPLY_API_TOKENS_REQUIRE_PAID_PLAN=true
```

Existing tokens stay revocable regardless. Standard and Enterprise satisfy the gate.

## Related

- [Organization roles & plan limits](/docs/org-roles-and-limits)
