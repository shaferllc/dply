# Dply pricing & revenue projections

As implemented in this repo (`config/product/subscription.php`, `config/product/dply.php`,
`config/product/realtime.php`, `config/product/lookout.php`, `config/product/queue_service.php`,
`config/servers/logs.php`, and the Billing module services). Amounts are **USD** unless noted.

**Last synced from codebase:** 2026-08-26
**Pricing model:** flat plans metered by BYO server **count** (Free / Starter / Pro / Business);
dply-owned infra billed cost-plus; managed services billed per capacity tier.

---

## What actually bills today

Not every priced line is live. Three carry a billing kill-switch that is currently **off**, so the
metering and estimate paths run dark and the customer is charged nothing. Check this table before
using any number below in a forecast.

| Line | Billing gate | Live? |
|---|---|---|
| Plan fee (Free → Business) | — | ✅ |
| dply Cloud platform fee + metered DO resources | — | ✅ |
| dply Edge platform fee | — | ✅ |
| dply Edge delivery usage | `dply.edge.usage_billing.enabled` (default **true**) | ✅ |
| dply-managed servers (cost-plus) | — | ✅ |
| Managed Realtime (per connection tier) | — | ✅ |
| Managed Lookout (per project tier) | `lookout.billing_enabled` = **false** (hardcoded) | ❌ dark |
| dply Queue (per capacity tier) | `queue_service.billing.enabled` = **false** (hardcoded) | ❌ dark |
| dply Queue managed worker usage | same switch | ❌ dark |
| dply Logs ingest overage | `server_logs.billing.enabled` = **false** (env) — and every plan's `overage_per_gb_cents` is 0, and no Stripe price id is set | ❌ dark |

A line also silently no-ops when its Stripe price ID is unset — `StripeSubscriptionSyncer` skips any
item with an empty price id rather than failing.

> **Serverless was removed from the app.** `app/Modules/Serverless` no longer exists and there is no
> `serverless_cents` key, no field on `DesiredBillingState`, and no Stripe price. Earlier revisions of
> this document listed a "$2/function" line in the product summary and the MRR planning formula; it
> billed nothing and has been removed. One vestige remains in code: `QueueNamespace::isBillable()`
> exempts namespaces attached to a `dply_serverless` site, a condition nothing can now satisfy, so
> **every** queue namespace is billable.

---

## Overview

Dply uses **flat self-serve plans** priced by the **number of BYO servers** an organization manages,
plus **Enterprise** for sales-led deals. Pricing is **organization-scoped**, not per-seat:

- **Unlimited team members**
- **Unlimited sites** on BYO VMs (subject to per-surface plan ceilings)
- **Servers** are what the plan is metered on — the tier is chosen by the **count** of billable BYO
  servers, not their size

You charge for:

1. A flat **plan fee** chosen by billable BYO server count (Free → Business)
2. **dply-owned infrastructure** at cost-plus — managed servers, Cloud container/database/bucket
   resources, Edge delivery usage
3. Flat **platform fees** per managed unit (Cloud app, Edge site)
4. **Managed services** by capacity tier (Realtime, Lookout, Queue)

**Important:** the plan fee bills for **platform work**, not the customer's cloud invoice. A $5
Hetzner box and a $500 AWS instance pay the same plan — what matters is how many servers dply
manages. This mirrors the proven Ploi / Forge / RunCloud model and sits inside the $8–39 cluster.
Anything running on **dply-owned** infra is billed separately at cost-plus, because dply pays that
provider bill directly.

---

## Plans

### Trial (14 days)

| | |
|---|---|
| **Price** | $0 — no credit card required |
| **Duration** | 14 days (`SUBSCRIPTION_TRIAL_DAYS`, default `14`) |
| **Includes** | Full product — real servers, deploys, scheduler, etc. |
| **Servers / sites** | Unlimited BYO servers; per-surface app ceilings still apply |

**After trial (no payment method):**

| Phase | Timing | What happens |
|---|---|---|
| **Soft pause** | Day 15 → day 45 | Deploys and on-demand scheduler runs **pause**. UI stays usable; standing automation (metrics, backups, drains) continues so dashboards and billing stay accurate. |
| **Hard pause** | After day 45 | Standing automation stops — agent telemetry no longer accepted. Config preserved; adding payment restores service. On-demand actions (manual backup, export, SSH, read-only dashboard) stay available indefinitely as the exit ramp. |

Soft-pause window: `SUBSCRIPTION_SOFT_PAUSE_DAYS` (default **30**).

**Free-zone exemption:** an org whose current fleet bills to nothing this cycle
(`owesNothingThisCycle()` → `OrganizationBillingStateComputer::isFree()`) is **never paused**. That
test accounts for managed products, so a Free-plan org running a Cloud app *does* owe and *does*
enter the ladder. A single-BYO-server account with nothing managed lives on Free indefinitely
without a card.

> **Known gap:** the pause ladder gates deploys, the scheduler, and metrics ingest — dply's costs for
> **BYO** servers. It does **not** suspend or tear down anything dply pays a provider for: a running
> Cloud container, its managed database, an Edge Worker, or a managed VM all keep running through
> both pause phases. There is no delinquency/`past_due` teardown path in `app/`.

### Self-serve plans

Metered by **billable BYO server count**. `SubscriptionPlanResolver` picks the cheapest plan whose
`max_servers` ceiling covers the count.

| Plan | USD/mo | Servers | Sites | Cloud apps | Edge apps |
|---|---:|---|---|---|---|
| **Free** | **$0** | 1 | 1 | 1 | 3 |
| **Starter** | **$9** | Up to 3 | 10 | 10 | 25 |
| **Pro** | **$19** | Up to 10 | 30 | 30 | 100 |
| **Business** | **$39** | Unlimited | Unlimited | Unlimited | Unlimited |

The four app ceilings are **per surface and independent** (`App\Enums\QuotaSurface`), enforced as a
hard block at creation. Filling up on Edge sites must not block a VM site. The plan tier itself is
still chosen by BYO server count alone.

| | |
|---|---|
| **Billing** | Stripe Checkout + Cashier; monthly or annual |
| **Annual discount** | **20% off** when paid yearly (`annual_discount_pct`) |
| **Feature gating** | None — every plan ships every feature; plans differ on ceilings and entitlements |
| **Age grace** | Servers younger than **1 day** don't count (`SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS`) |

**Example monthly totals (before tax):**

| Profile | BYO servers | Plan | Monthly |
|---|---|---|---:|
| First project | 1 | Free | **$0** |
| Indie / small team | 3 | Starter | **$9** |
| Growing team | 8 | Pro | **$19** |
| Agency / large fleet | 25 | Business | **$39** |

**Example annual (20% off):** Starter **~$86/yr** · Pro **~$182/yr** · Business **~$374/yr**

> **Known gap:** the plan tier is driven by BYO server count only. Managed VMs, Cloud apps, and Edge
> sites are all excluded from it by design. A customer who uses **only** managed products therefore
> resolves to **Free forever** — no plan fee, capped at 1 Cloud app / 3 Edge sites — and
> `quotaLimitMessage()` tells them to *"Add a server to move up to the next plan"*, i.e. buy a BYO VM
> they don't want. There is currently no upgrade path for a managed-only customer.

### Closed beta

An org with `organizations.beta_joined_at` set is a beta participant: the platform fee is waived,
trial and soft-pause are suppressed, and the beta envelope replaces the plan ceilings until
`beta.cutover_at`.

| Beta allowance | Default |
|---|---:|
| BYO servers | 5 |
| dply-managed servers (free CX22 grant) | 1 |
| Sites | 25 |
| Cloud apps | 10 |
| Edge apps | 25 |
| Invite expiry | 30 days |

### Enterprise

| | |
|---|---|
| **Price** | Custom — negotiated in Stripe (`STRIPE_PRICE_ENTERPRISE`) |
| **Typical adds** | Volume pricing, MSA, SSO, audit logs, dedicated support / SLA |
| **How to buy** | Sales-led; manual Stripe subscription |

---

## BYO servers (VM / SSH-managed)

Ready VM hosts the customer SSHs into count toward the **plan tier**. Size is not billed — the
customer already pays their provider for it.

- **Count basis:** plan tier = cheapest plan whose ceiling ≥ billable server count
- **Age grace:** servers younger than **1 day** are not counted
- **Status:** must be `ready`
- **Excluded:** dply-managed VMs and managed-product logical hosts (Cloud, Edge) never count
- **Provider:** customer pays DigitalOcean / Hetzner / AWS / etc. **directly**

---

## dply-managed servers (cost-plus, all-in)

Managed VMs run on **dply-owned** cloud accounts (Hetzner or Vultr, per
`ServerHostingPlatformContext`), so dply pays that provider. They are billed
**provider cost × (1 + markup)** as a single all-in monthly price and do **not** count toward the
plan tier. Billed via a metered Stripe line (quantity = cents).

**Markup:** `SUBSCRIPTION_MANAGED_SERVER_MARKUP_PERCENT`, default **60%**.

| Size | Raw provider cost/mo | Customer price/mo | Gross margin |
|---|---:|---:|---:|
| Hetzner `cx22` | $4.50 | **$7.20** | $2.70 |
| Hetzner `cx32` | $7.40 | **$11.84** | $4.44 |
| Hetzner `cx42` | $17.90 | **$28.64** | $10.74 |
| Hetzner `cx52` | $33.30 | **$53.28** | $19.98 |
| Vultr `vc2-1c-2gb` | $10.00 | **$16.00** | $6.00 |
| Vultr `vc2-2c-4gb` | $20.00 | **$32.00** | $12.00 |
| Vultr `vc2-4c-8gb` | $40.00 | **$64.00** | $24.00 |
| Vultr `vc2-6c-16gb` | $80.00 | **$128.00** | $48.00 |

Raw values are approximate provider list prices verified 2026-05; Hetzner's are EUR-denominated, so
FX moves against a USD sale price.

> **Known gaps.** (1) A managed VM contributes ~37.5% gross margin and does **not** advance the plan
> tier, while the same box as BYO contributes ~100%-margin plan revenue *and* counts toward the tier
> — so converting a customer BYO → managed lowers both margin rate and plan revenue. (2) Outside
> beta, `canCreateManagedServer()` returns `true` unconditionally: managed VMs are **uncapped**, and
> the only gate before real provider spend is a verified email address (`StoreManagedServer.php:47`,
> *"no card-on-file during beta"*).

---

## dply Cloud — platform fee + metered resources

Cloud apps run on dply-owned DigitalOcean infrastructure, so **both** parts bill.

### Platform fee

**$5/mo** per live production app (`cloud_cents`) — covers builds, deploys, scaling, TLS,
dashboards, and orchestration. Branch previews are excluded (previews never consume quota or bill).

### Metered provider resources (cost-plus)

DO list price × **(1 + `cloud_markup_percent`)**, default **40%**. Billed on a metered Stripe line
(quantity = cents) on top of the flat fee. A flat $5 alone loses money the moment an app attaches a
database.

| Resource | Tier | Raw DO cost/mo | Customer/mo |
|---|---|---:|---:|
| Container / worker | `small` (basic-xxs) | $5.00 | **$7.00** |
| | `medium` (basic-xs) | $10.00 | **$14.00** |
| | `large` (basic-s) | $20.00 | **$28.00** |
| | `xlarge` (basic-m) | $40.00 | **$56.00** |
| | `small-pro` (apps-d-*) | $29.00 | **$40.60** |
| | `medium-pro` | $34.00 | **$47.60** |
| | `large-pro` | $39.00 | **$54.60** |
| | `xlarge-pro` | $78.00 | **$109.20** |
| Managed database | `small` (db-s-1vcpu-1gb) | $15.00 | **$21.00** |
| | `medium` (db-s-1vcpu-2gb) | $30.00 | **$42.00** |
| | `large` (db-s-2vcpu-4gb) | $60.00 | **$84.00** |
| Bucket (DO Spaces, 250 GiB) | — | $5.00 | **$7.00** |

Per-instance, per attached resource. A `small` app with one `small` database therefore bills
$5 + $7 + $21 = **$33/mo**.

### AWS App Runner (BYO credential)

App Runner apps run on the **customer's** AWS account, so dply bills only the flat $5 platform fee.
The App Runner rates in config (`app_runner_vcpu_usd_per_hour` 0.064,
`app_runner_memory_gb_usd_per_hour` 0.007, 730 h/mo) drive the **create-flow cost estimate only** and
never reach `CloudResourceCostCalculator` or Stripe.

---

## dply Edge — platform fee + delivery usage

### Platform fee

| Site type | Fee/mo | Key |
|---|---:|---|
| Static / SSG / hybrid | **$2.00** | `edge_cents` |
| Worker-native SSR | **$7.00** | `edge_ssr_cents` |

Edge static is genuinely flat-eligible: Workers Paid is $5/mo per *account* amortized across the
fleet, and R2/Pages egress is free, so the marginal cost of another static site is ~$0. Hybrid sites
stay on the $2 fee — their Cloud origin bills separately. Branch previews are excluded.

### Delivery usage

`DPLY_EDGE_USAGE_BILLING_ENABLED` — **default `true`** (enabled). Applies to managed delivery
(`dply_edge`) only; BYO Cloudflare sites pay Cloudflare directly and are not metered.

**Included allowance per live Edge site / month:**

| Allowance | Default |
|---|---:|
| HTTP requests | 5,000,000 |
| Bandwidth egress | 100 GB |
| R2 storage | 5 GB |
| R2 Class A ops (writes) | 100,000 |
| R2 Class B ops (reads) | 1,000,000 |

**Overage rates.** Config stores a base rate; the customer pays base × **(1 + `markup_percent`)**,
default **40%**. Both columns below are per the same unit.

| Meter | Base rate (config) | **Customer rate** |
|---|---:|---:|
| Requests | $0.50 / million | **$0.70 / million** |
| Bandwidth egress | $0.05 / GB | **$0.07 / GB** |
| R2 storage | $0.03 / GB-month | **$0.042 / GB-month** |
| R2 Class A ops | $4.50 / million | **$6.30 / million** |
| R2 Class B ops | $0.36 / million | **$0.50 / million** |

> The base rates are labelled "cost-floor" in config, but two of them are not costs: Cloudflare's
> request list price is ~$0.30/M (config uses $0.50) and R2/Workers egress is $0 (config uses
> $0.05/GB). The prices are defensible; the label is not. Anyone re-tuning `markup_percent` should
> know a margin is already baked into the base.
>
> Class B was previously defaulted to 360 cents/million — a 10× typo against R2's $0.36 list that
> billed customers ten times the real cost. Fixed; noted here so a stale copy isn't restored.

---

## Managed services (per capacity tier)

Realtime, Lookout, and Queue all bill the same way: one Stripe line per tier in use, quantity = the
number of units on that tier. Yearly prices are derived from monthly with the standard 20% annual
discount by `StripeBillingProvisioner` — one discount policy, one place to change it.

### Managed Realtime — **live**

Per broadcasting app, priced by its concurrent-connection cap (enforced as a hard cap at the Worker).

| Tier | Max connections | USD/mo |
|---|---:|---:|
| Starter (default) | 5,000 | **$15** |
| Growth | 25,000 | **$49** |
| Scale | 100,000 | **$149** |

`subscription.standard.realtime_cents` ($9) is a **legacy** flat fallback retained only so the syncer
can strip the old flat line off subscriptions migrated from the v1 model. It is not the current price.

### Managed Lookout (error tracking) — **billing dark**

Per project, above a free-project allowance. `free_projects_per_org` = **1** (loss-leader);
additional projects bill at their tier. `lookout.billing_enabled` is hardcoded `false`, so
`OrganizationBillingStateComputer` currently adds no Lookout line and every project provisions free.

| Tier | Retention | Monthly events | USD/mo |
|---|---:|---:|---:|
| Starter (default) | 7 days | 100,000 | **$15** |
| Growth | 30 days | 1,000,000 | **$49** |
| Scale | 90 days | 10,000,000 | **$149** |

### dply Queue — **billing dark**

Per namespace, priced by reserved capacity. Not metered per job: `requests_per_minute` caps COGS
structurally. `queue_service.billing.enabled` is hardcoded `false`.

| Tier | Max queue depth | Requests/min | USD/mo |
|---|---:|---:|---:|
| Standard (default) | 100,000 | 600 | **$9** |
| Pro | 500,000 | 1,200 | **$29** |

**Namespace allowance per plan** (`queue_service.entitlements`) — **0 means unlimited**, a fail-open
convention shared with Logs:

| Plan | Max namespaces |
|---|---|
| Free | 1 |
| Starter | 2 |
| Pro | 10 |
| Business | **Unlimited** (`0`) |

**Managed worker fleets** — dply-owned workers that drain a namespace, billed as a separate metered
line from the capacity tier (a tier prices a queue the customer polls; it cannot price compute dply
runs on their behalf). Rates are in **millicents**, because a MiB-second in whole cents is either
zero or absurd:

| Quantity | Rate |
|---|---:|
| Flex worker time | 0.0000594 millicents / MiB-second |
| Pro worker time | Flex × **1.20** |
| Job operations | 100,000 millicents / million ops ($1.00 / million) |

The fleet `runtime` defaults to `fake` on purpose — a real runtime starts containers running
customer code on dply machines, which has to be a decision someone made rather than a default they
inherited.

---

## dply Logs (server log add-on) — **billing dark**

Ingest overage on a metered Stripe line. Three independent conditions must all be true before any
org is charged, and none of them are today:

1. `server_logs.billing.enabled` (env `SERVER_LOGS_BILLING_ENABLED`) — default **false**
2. The org's plan carries a non-zero `overage_per_gb_cents` — **0 on every plan**
3. `subscription.standard.stripe.server_log_usage` price id is set — **unset**

Per-plan entitlements (the free MVP baseline matches pre-billing behaviour exactly, so flipping the
switch changes nothing for current users):

| Plan | Retention | Included ingest | Overage | Alerting | Drains |
|---|---:|---:|---:|---|---|
| Free / Starter (defaults) | 7 days | 1 GB | $0 | ✗ | ✗ |
| Pro | 30 days | 10 GB | $0 | ✓ | ✓ |
| Business | 90 days | 50 GB | $0 | ✓ | ✓ |

Volume and retention numbers are uncalibrated placeholders pending dogfooding.

---

## Subscription math

```
Monthly total =
  plan fee                    (Free $0 / Starter $9 / Pro $19 / Business $39, by BYO server count)
+ managed servers             (Σ provider cost × 1.60)
+ Cloud apps × $5             (platform fee)
+ Cloud resources             (Σ DO cost × 1.40 — containers, workers, databases, buckets)
+ Edge sites × $2             ($7 for Worker-native SSR)
+ Edge delivery usage         (overage beyond per-site allowance × 1.40)
+ Realtime apps               (Σ by connection tier: $15 / $49 / $149)
[+ Lookout projects           (Σ by tier, minus 1 free)          — dark]
[+ Queue namespaces           (Σ by capacity tier: $9 / $29)     — dark]
[+ Queue worker usage         (MiB-seconds + job ops)            — dark]
[+ Logs ingest overage        (per GB above plan allowance)      — dark]
```

Managed products bill on **any** plan, including Free. Stripe requires a uniform billing interval per
subscription, so every priced item has both a monthly and a yearly price; crossing a plan ceiling
triggers a prorated Stripe plan swap.

> **Known gap:** nothing charges an org that never visits the billing page.
> `StripeSubscriptionSyncer::reconcile()` returns immediately when the org has no valid subscription,
> and the only caller of `StandardSubscriptionCreator` is `Billing\Livewire\Show` — subscription
> creation is entirely user-initiated. `SubscriptionPlanResolver::isPaidPlan()` has **zero callers**,
> so the "managed products require a paid plan" rule is not enforced anywhere. A Free org with no
> payment method can therefore run 1 Cloud app, 3 Edge sites, and unbounded managed VMs on
> dply-funded infrastructure at $0.

---

## Trial / access gating

| State | Deploys & scheduler | Standing automation (metrics, backups, drains) |
|---|---|---|
| Active trial | ✅ | ✅ |
| Subscribed (paid plan / Enterprise) | ✅ | ✅ |
| Free-zone (owes nothing this cycle) | ✅ | ✅ |
| Soft pause (expired trial, owes money) | ❌ | ✅ |
| Hard pause (owes money) | ❌ | ❌ |

Neither pause phase stops dply-owned infrastructure (see the note under Trial, above).

Optional: `DPLY_API_TOKENS_REQUIRE_PAID_PLAN=true` gates **creating new** API tokens behind an active
paid plan.

---

## Configuration reference

| Setting | Default | Purpose |
|---|---|---|
| `subscription.standard.plans.free` | $0 / 1 server | Always-free entry plan |
| `subscription.standard.plans.starter` | $9 / ≤3 | Starter plan |
| `subscription.standard.plans.pro` | $19 / ≤10 | Pro plan |
| `subscription.standard.plans.business` | $39 / unlimited | Business plan |
| `subscription.standard.annual_discount_pct` | 20 | Annual discount |
| `subscription.standard.min_billable_age_days` | 1 | New-server grace |
| `subscription.standard.trial_days` | 14 | Trial length |
| `subscription.standard.soft_pause_days` | 30 | Post-trial soft window |
| `subscription.standard.managed_server_markup_percent` | 60 | Managed VM cost-plus markup |
| `subscription.standard.managed_server_cents` | per slug | Raw provider cost, managed VMs |
| `subscription.standard.cloud_cents` | 500 | $5/app platform fee |
| `subscription.standard.cloud_markup_percent` | 40 | Cloud resource cost-plus markup |
| `subscription.standard.cloud_container_cents` | per tier | Raw DO container cost |
| `subscription.standard.cloud_database_cents` | per tier | Raw DO managed-DB cost |
| `subscription.standard.cloud_bucket_cents` | 500 | Raw DO Spaces cost |
| `subscription.standard.edge_cents` | 200 | $2/site (static/hybrid) |
| `subscription.standard.edge_ssr_cents` | 700 | $7/site (Worker-native SSR) |
| `subscription.standard.beta.*` | see Closed beta | Beta envelope + cutover date |
| `dply.edge.usage_billing.enabled` | **true** | Edge metered usage master switch |
| `dply.edge.usage_billing.markup_percent` | 40 | Edge overage markup |
| `realtime.tiers` | $15 / $49 / $149 | Realtime connection tiers |
| `lookout.billing_enabled` | **false** | Lookout billing master switch |
| `lookout.tiers` | $15 / $49 / $149 | Lookout project tiers |
| `lookout.free_projects_per_org` | 1 | Free Lookout projects |
| `queue_service.billing.enabled` | **false** | Queue billing master switch |
| `queue_service.tiers` | $9 / $29 | Queue capacity tiers |
| `queue_service.fleets.pricing` | see above | Managed worker metered rates |
| `server_logs.billing.enabled` | **false** | Logs billing master switch |
| `server_logs.entitlements` | see above | Per-plan retention / included GB |

Related docs: [Billing & plans](./BILLING_AND_PLANS.md), [Edge billing](./EDGE_BILLING.md),
[Server logs billing](./SERVER_LOGS_BILLING.md).

> **Note:** [Organization roles & plan limits](./ORG_ROLES_AND_LIMITS.md) mentions trial caps
> (3 servers / 10 sites). The current `Organization` model returns **unlimited** BYO servers outside
> beta — trial enforcement is via deploy/metrics gating, not numeric server caps. Per-surface app
> ceilings do apply.

---

# Revenue projections

In Dply's model, **"users" = organizations** (workspaces). Seats are unlimited, so revenue scales
with **orgs × plan tier**, plus infrastructure attach.

Figures below are **gross revenue** (before Stripe fees, infra COGS, support, tax). Note that
cost-plus lines carry only their markup as margin — $33 of Cloud revenue is ~$8 gross — so blending
them into an ARPU number overstates contribution. The plan fee is the ~100%-margin line.

---

## Per-org revenue (quick reference)

| Customer type | Plan | **$/mo** | **$/yr** |
|---|---|---:|---:|
| First project | Free (1 server) | **$0** | $0 |
| Indie / small team | Starter (≤3) | **$9** | $108 |
| Growing team | Pro (≤10) | **$19** | $228 |
| Agency / large fleet | Business (unlimited) | **$39** | $468 |
| + Edge | Above + 2 live static Edge sites | **+$4** | +$48 |
| + Cloud | Above + 1 small app, no database | **+$12** | +$144 |
| + Cloud w/ database | Above + 1 small app + small DB | **+$33** | +$396 |
| + Realtime | Above + 1 Starter-tier app | **+$15** | +$180 |
| + Managed VM | Above + 1 cx22 | **+$7.20** | +$86 |

**Blended planning estimate:** **~$14–18/org/mo** for paying orgs on plan fees alone, before
infrastructure attach — a Starter-heavy paid mix with some Pro/Business and a meaningful free base.

---

## Paying-organization scenarios

"Paying" excludes Free-plan orgs. Blended ARPU assumes a Starter-heavy mix with light attach.

| Paying orgs | Avg $/org/mo | **MRR** | **ARR** |
|---:|---:|---:|---:|
| 10 | $15 | **$150** | **~$1,800** |
| 100 | $15 | **$1,500** | **~$18,000** |
| 1,000 | $15 | **$15,000** | **~$180,000** |
| 1,000 (Pro-heavy, ~$22) | $22 | **$22,000** | **~$264,000** |

---

## Adjustments that move the forecast

### Free-to-paid conversion

Only orgs that cross **2+ BYO servers** become paying on the plan line. Managed-product usage does
*not* move an org off Free (see the packaging gap above), so the free single-server tier is an
acquisition funnel — model conversion off the free base, not trials alone.

### Annual billing (−20%)

If roughly half of revenue is on annual plans, effective MRR is about **8–10% lower** than list
monthly prices.

### Infrastructure attach

Cloud and managed VMs add the most revenue per org but the least margin — they are cost-plus at 40%
and 60% respectively. Edge platform fees ($2/$7) and Realtime tiers ($15+) are the high-margin
attach: their marginal COGS is near zero.

### Dark lines

Flipping Lookout, Queue, and Logs billing on is pure upside against today's numbers — the metering
already runs. Sizing that requires real dogfooding volume, which is why the switches are off.

### Enterprise

A handful of large fleet deals can dominate total revenue vs many small orgs.

---

## Planning formula

```
MRR ≈ Σ (paying orgs × plan fee by BYO server count)
    + (managed VM provider cost × 0.60)          ← margin, not revenue
    + (Cloud apps × $5) + (Cloud resource cost × 0.40)
    + (Edge static sites × $2) + (Edge SSR sites × $7)
    + (Edge usage overage × 0.40)
    + (Realtime apps × tier price)
```

**Rule of thumb for 1,000 paying orgs (plan fees only):**

| Outlook | Per-org/mo | MRR | ARR |
|---|---:|---:|---:|
| Pessimistic | $9–15 | $9k–15k | $108k–180k |
| Realistic | $15–22 | $15k–22k | $180k–264k |
| Optimistic | $22–39+ | $22k–39k+ | $264k–468k+ |

---

## Product lines summary

| Line | Who pays infra | Dply charges |
|---|---|---|
| **BYO VMs** | Customer → provider | Flat plan by server count (Free → Business) |
| **dply-managed VMs** | **Dply** → Hetzner / Vultr | Provider cost × 1.60, all-in; does not advance plan tier |
| **dply Cloud (DO)** | **Dply** → DigitalOcean | $5/app + (DO resource cost × 1.40) |
| **dply Cloud (App Runner)** | Customer → AWS | $5/app platform fee only |
| **dply Edge (managed)** | **Dply** → Cloudflare | $2/site static·hybrid, $7/site SSR, + usage × 1.40 |
| **dply Edge (BYO CF)** | Customer → Cloudflare | $2/site platform fee only; usage not metered |
| **Realtime** | **Dply** → Cloudflare / DO | $15 / $49 / $149 by connection tier |
| **Lookout** | **Dply** | $15 / $49 / $149 by project tier — *dark* |
| **Queue** | **Dply** → Postgres / fleets | $9 / $29 by capacity tier + worker usage — *dark* |
| **Logs** | **Dply** → ClickHouse | Ingest overage per GB — *dark* |

---

# Competitive benchmarks (estimated)

Competitors do **not** publish MRR. Figures below combine **public pricing** with **customer counts
or signals** where available. Revenue ranges are **inference**, not verified financials.

---

## Laravel Forge

**Source:** [Forge pricing](https://laravel.com/forge/pricing); Laravel leadership interview (2026);
Laravel blog ("tens of thousands of active customers").

| Plan | USD/mo | Servers |
|---|---:|---|
| Hobby | $12 | 1 external (+ unlimited Laravel VPS) |
| Growth | $19 | Unlimited |
| Business | $39 | Unlimited |

~27,000 customers; blended ARPU ~$15–25/mo → planning range **~$400k–700k/month** (~$5M–8M/year) for
the panel alone, before VPS upsell.

## Ploi.io

**Source:** [Ploi pricing](https://ploi.io/pricing); [2025 recap](https://ploi.io/news/recap-ploi-2025);
also runs [Ploi Cloud](https://ploi.cloud/pricing).

| Plan | USD/mo | Servers |
|---|---:|---|
| Free | $0 | 1 |
| Basic | $10 | Up to 5 |
| Pro | $16 | Up to 10 |
| Unlimited | $36 | Unlimited |

Bootstrapped since 2018; MRR never disclosed. Planning range **~$10k–40k/month** for the classic
panel, plus Ploi Cloud + lifetime sales.

## Competitor comparison summary

| | **Laravel Forge** | **Ploi** | **Dply** |
|---|---|---|---|
| **Pricing model** | $12 / $19 / $39 flat | $0 / $10 / $16 / $36 by count | **$0 / $9 / $19 / $39 by count** |
| **Free tier** | No (Hobby $12) | Yes (1 server) | **Yes (1 server)** |
| **Large fleet economics** | Cheap (unlimited on Growth) | Cheap (Unlimited $36) | Cheap (Business $39) |
| **Managed hosting upsell** | Laravel VPS, Cloud | Ploi Cloud | Managed VMs, Cloud, Edge, Realtime, Queue, Logs |

### Strategic takeaway for Dply

- **Free 1-server tier** matches Ploi's funnel and undercuts Forge's $12 entry — strong
  top-of-funnel for acquisition.
- **Starter $9 / Pro $19 / Business $39** sit directly inside the Ploi/Forge cluster, so Dply
  competes on product depth rather than price.
- **The managed portfolio is the differentiated upsell** and is far wider than either competitor's.
  But it currently bypasses the plan ladder entirely: managed usage neither advances the tier nor
  requires a paid plan, so the depth advantage does not yet convert into plan revenue. Closing that
  is the single largest pricing lever available.
- **Revenue ceiling:** a mature flat-rate panel with a dominant brand can reach **~$500k+ MRR**
  (Forge); the bootstrapped floor sits in the **tens of thousands MRR** range (Ploi).
