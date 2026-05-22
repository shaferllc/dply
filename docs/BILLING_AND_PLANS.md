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

dply prices its own work, not your cloud invoice — the **same fee applies whether you run on $5 Hetzner boxes or $500 AWS instances**.

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
