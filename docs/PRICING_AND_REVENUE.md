# Dply pricing & revenue projections

As implemented in this repo (`config/subscription.php`, `config/dply.php`, billing services). Amounts are **USD** unless noted.

**Last synced from codebase:** 2026-05-27  
**Competitive benchmarks added:** 2026-05-27

---

## Overview

Dply uses **one self-serve plan (Standard)** plus **Enterprise** for sales-led deals. Pricing is **organization-scoped**, not per-seat:

- **Unlimited team members**
- **Unlimited sites** (no per-site fee on BYO VMs)
- **Unlimited servers** (no hard cap in application code)

You charge for:

1. A fixed **organization base fee**
2. **Billable units** customers run (BYO servers by size tier, plus managed products)
3. Optional **Edge delivery usage** (metered, when enabled)

**Important:** Dply bills for **platform work**, not the customer's cloud invoice. A $5 Hetzner box and a $500 AWS instance at the same spec pay the **same Dply tier fee**.

---

## Plans

### Trial (14 days)

| | |
|---|---|
| **Price** | $0 — no credit card required |
| **Duration** | 14 days (`SUBSCRIPTION_TRIAL_DAYS`, default `14`) |
| **Includes** | Full product — real servers, deploys, scheduler, etc. |
| **Servers / sites** | Unlimited (no numeric cap in code) |

**After trial (no payment method):**

| Phase | Timing | What happens |
|---|---|---|
| **Soft pause** | Day 15 → day 45 | Deploys and on-demand scheduler runs **pause**. UI stays usable; infra on the provider keeps running. |
| **Hard pause** | After day 45 | Agent telemetry **stops accepting** new metrics. Config is preserved; adding payment restores service. |

Soft-pause window: `SUBSCRIPTION_SOFT_PAUSE_DAYS` (default **30**).

### Standard (self-serve)

| | |
|---|---|
| **Base fee** | **$15/mo** per organization |
| **Billing** | Stripe Checkout + Cashier; monthly or annual |
| **Annual discount** | **20% off** all line items when paid yearly |
| **Feature gating** | No tier gating on Standard |

**Example monthly totals (before tax):**

| Profile | Calculation | Monthly |
|---|---|---|
| Base only | $15 | **$15** |
| Base + 1 M-tier server | $15 + $10 | **$25** |
| Base + 5 M-tier servers | $15 + $50 | **$65** |

**Example annual (20% off):**

| Profile | Yearly |
|---|---|
| Base only | **$144/yr** |
| Base + 1 M server | **$240/yr** |
| Base + 5 M servers | **$624/yr** |

### Enterprise

| | |
|---|---|
| **Price** | Custom — negotiated in Stripe (`STRIPE_PRICE_ENTERPRISE`) |
| **Typical adds** | Volume pricing on server fees, MSA, SSO, audit logs, dedicated support / SLA |
| **How to buy** | Sales-led; manual Stripe subscription |

---

## BYO servers (VM / SSH-managed)

Ready VM hosts the customer SSHs into are **auto-tiered** from detected **vCPU + RAM**. Tier = **higher** of CPU bucket vs RAM bucket.

| Tier | Typical specs | Per server / month | Per server / day* |
|---|---|---|---|
| **XS** | ≤1 vCPU, ≤2 GB RAM | **$2** | ~$0.07 |
| **S** | 2 vCPU, ≤4 GB | **$5** | ~$0.17 |
| **M** | ≤4 vCPU, ≤8 GB | **$10** | ~$0.33 |
| **L** | ≤8 vCPU, ≤16 GB | **$20** | ~$0.67 |
| **XL** | Above L | **$40** | ~$1.33 |

\*Marketing/UI shows per-day as monthly ÷ 30.

**Rules:**

- **Cap:** never more than **$40/server/mo** (XL ceiling)
- **Age grace:** servers younger than **1 day** are not billable (`SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS`, default `1`)
- **Status:** must be `ready`
- **Excluded:** dply-managed logical hosts (Cloud, Edge, serverless namespaces)
- **Provider:** customer pays DigitalOcean / Hetzner / AWS / etc. **directly**

**Example fleets (monthly, Standard base included):**

| Profile | Fleet | Total |
|---|---|---|
| Indie dev | 1 XS | **$17/mo** |
| Side project | 1 M | **$25/mo** |
| Small team | 3 M | **$45/mo** |
| Growing fleet | 5 M + 2 L | **$105/mo** |

---

## Managed products (flat platform fees)

| Product | Billable unit | Default fee | Notes |
|---|---|---|---|
| **dply Cloud** | Per live production app | **$5/mo** | Container apps on `dply_cloud`; branch previews excluded |
| **dply Edge** | Per live production site | **$2/mo** | Static/SSG on managed `dply_edge`; branch previews excluded |
| **Serverless** | Per code function | **$2/mo** | Active function sites / code actions |

---

## dply Edge — platform + delivery usage

### Platform fee

**$2/mo per live production Edge site.**

### Delivery usage (optional)

Controlled by `DPLY_EDGE_USAGE_BILLING_ENABLED` (default **off**).

**Applies to managed delivery (`dply_edge`) only.** BYO Cloudflare sites: customer pays Cloudflare directly; Dply does not meter that usage today.

**Default included allowances per live Edge site / month** (`config/dply.php`):

| Allowance | Default |
|---|---|
| HTTP requests | 5,000,000 |
| Bandwidth egress | 100 GB |
| R2 storage | 5 GB |
| R2 Class A ops (writes) | 100,000 |
| R2 Class B ops (reads) | 1,000,000 |

**Default overage rates** (env-overridable):

| Meter | Default rate |
|---|---|
| Requests | **$0.30** / million |
| Bandwidth egress | **$0.02** / GB |
| R2 storage | **$0.02** / GB-month |
| R2 Class A ops | **$4.50** / million |
| R2 Class B ops | **$3.60** / million |

---

## Subscription math

```
Monthly total =
  $15 base
+ Σ (BYO server tier fees)
+ (serverless count × $2)
+ (Cloud apps × $5)
+ (Edge sites × $2)
+ Edge delivery usage (if enabled)
− included_credit (currently $0)
```

Stripe requires **uniform billing interval** per subscription. Adding a server mid-cycle → Stripe prorates.

---

## Trial / access gating

| State | Deploys & scheduler | Agent metrics |
|---|---|---|
| Active trial | ✅ | ✅ |
| Subscribed (Standard / Enterprise) | ✅ | ✅ |
| Soft pause (expired trial) | ❌ | ✅ |
| Hard pause | ❌ | ❌ |

Optional: `DPLY_API_TOKENS_REQUIRE_PAID_PLAN=true` gates **creating new** API tokens behind an active paid plan.

---

## Configuration reference

| Setting | Default | Purpose |
|---|---|---|
| `subscription.standard.base_cents` | 1500 | Org base ($15) |
| `subscription.standard.tiers.*` | 200–4000 | XS–XL cents |
| `subscription.standard.serverless_cents` | 200 | $2/function |
| `subscription.standard.cloud_cents` | 500 | $5/app |
| `subscription.standard.edge_cents` | 200 | $2/site |
| `subscription.standard.annual_discount_pct` | 20 | Annual discount |
| `subscription.standard.min_billable_age_days` | 1 | New-server grace |
| `subscription.standard.trial_days` | 14 | Trial length |
| `subscription.standard.soft_pause_days` | 30 | Post-trial soft window |
| `DPLY_EDGE_USAGE_BILLING_ENABLED` | false | Edge metered usage |

Related docs: [Billing & plans](./BILLING_AND_PLANS.md), [Edge billing](./EDGE_BILLING.md).

> **Note:** [Organization roles & plan limits](./ORG_ROLES_AND_LIMITS.md) mentions trial caps (3 servers / 10 sites). The current `Organization` model returns **unlimited** servers and sites — trial enforcement is via deploy/metrics gating, not numeric caps.

---

# Revenue projections

In Dply’s model, **“users” = organizations** (workspaces). Seats are unlimited, so revenue scales with **orgs × what each org runs**, not headcount.

Figures below are **gross revenue** (before Stripe fees, infra, support, tax, etc.).

---

## Per-org revenue (quick reference)

| Customer type | Typical stack | **$/mo** | **$/yr** |
|---|---|---:|---:|
| Minimal | Base + 1 XS server | **$17** | $204 |
| Common indie | Base + 1 M server | **$25** | $300 |
| Small team | Base + 3 M servers | **$45** | $540 |
| Heavier BYO | Base + 5 M + 2 L | **$105** | $1,260 |
| + Edge | Above + 2 live Edge sites | **+$4** | +$48 |
| + Cloud | Above + 1 Cloud app | **+$5** | +$60 |

**Blended planning estimate:** **~$28–30/org/mo** if the mix is mostly 1-server accounts, some 3-server teams, and a few large fleets.

---

## 10 paying organizations

| Scenario | Avg $/org/mo | **MRR** | **ARR** |
|---|---:|---:|---:|
| All minimal (1 XS) | $17 | **$170** | **$2,040** |
| All typical (1 M) | $25 | **$250** | **$3,000** |
| All small team (3 M) | $45 | **$450** | **$5,400** |
| Blended (~$29) | $29 | **$290** | **~$3,480** |

---

## 100 paying organizations

| Scenario | Avg $/org/mo | **MRR** | **ARR** |
|---|---:|---:|---:|
| All minimal | $17 | **$1,700** | **$20,400** |
| All typical | $25 | **$2,500** | **$30,000** |
| All small team | $45 | **$4,500** | **$54,000** |
| Blended (~$29) | $29 | **$2,900** | **~$34,800** |

---

## 1,000 paying organizations

| Scenario | Avg $/org/mo | **MRR** | **ARR** |
|---|---:|---:|---:|
| All minimal | $17 | **$17,000** | **$204,000** |
| All typical | $25 | **$25,000** | **$300,000** |
| All small team | $45 | **$45,000** | **$540,000** |
| Blended (~$29) | $29 | **$29,000** | **~$348,000** |

---

## Adjustments that move the forecast

### Trial conversion

Only **paying** orgs count. At **70% conversion** from trial (blended ~$29/org):

| Paying orgs | **MRR** | **ARR** |
|---:|---:|---:|
| 10 | **$203** | **~$2,436** |
| 100 | **$2,030** | **~$24,360** |
| 1,000 | **$20,300** | **~$243,600** |

### Annual billing (−20%)

If roughly half of revenue is on annual plans, effective MRR is about **8–10% lower** than list monthly prices.

### Managed products uplift

Example: 1,000 orgs, each with 1 M server + 1 Edge site → **$27/org** → **$27k MRR / ~$324k ARR**.

### Edge usage billing

High-traffic Edge sites add variable revenue on top of the flat $2/site platform fee.

### Enterprise

A handful of large fleet deals (20–100+ servers) can dominate total revenue vs many small orgs.

---

## Planning formula

```
MRR ≈ (paying orgs × $15 base)
    + (billable BYO servers × tier fee)
    + (Cloud apps × $5)
    + (Edge sites × $2)
    + (serverless functions × $2)
    + (Edge usage overages, if enabled)
```

**Rule of thumb for 1,000 paying orgs:**

| Outlook | Per-org/mo | MRR | ARR |
|---|---:|---:|---:|
| Pessimistic | $17–25 | $17k–25k | $204k–300k |
| Realistic | $25–45 | $25k–45k | $300k–540k |
| Optimistic | $45–105+ | $45k–105k+ | $540k–1.26M+ |

---

## Product lines summary

| Line | Who pays infra | Dply charges |
|---|---|---|
| **BYO VMs** | Customer → provider | Base + tiered server fee |
| **dply Cloud** | Dply / container backend | Base + $5/app |
| **dply Edge** | Dply / CF (managed) or customer (BYO CF) | Base + $2/site (+ usage if enabled) |
| **Serverless** | Customer → FaaS provider | Base + $2/function |

---

# Competitive benchmarks (estimated)

Competitors do **not** publish MRR. Figures below combine **public pricing** with **customer counts or signals** where available. Revenue ranges are **inference**, not verified financials.

Use these for positioning and planning — not investor reporting.

---

## Laravel Forge

**Source:** [Forge pricing](https://laravel.com/forge/pricing); Laravel leadership interview ([Rewiz summary](https://rewiz.app/channels/@nunomaduro/laravels-president-explains-the-57m-deal-thomas-crary-interview), 2026); [Laravel blog](https://laravel.com/blog/nightwatch-found-78k-exceptions-in-27b-events-on-forge) (“tens of thousands of active customers”).

### Public customer count

| Product | Customers (approx.) | Notes |
|---|---:|---|
| **Forge** | **~27,000** | ~11 years in market (as of 2026 interview) |
| Laravel Cloud | ~30,000 | Surpassed Forge in under 12 months |
| Laravel Nightwatch | ~20,000 | Monitoring product |

Forge is one product inside **Laravel LLC** (also Cloud, Vapor, Envoyer, Nova, Nightwatch, VPS). Company raised **$57M Series A** (Accel, 2024). Total Laravel commercial revenue is **higher** than Forge alone; third-party company-wide ARR estimates vary (~$6M–$46M) and are not Forge-specific.

### Pricing model

Flat-rate monthly — **not** per-server tiering:

| Plan | USD/mo | Servers |
|---|---:|---|
| Hobby | $12 | 1 external (+ unlimited Laravel VPS) |
| Growth | $19 | Unlimited |
| Business | $39 | Unlimited |

Panel fee excludes VPS/provider costs. **Laravel VPS** (sold through Forge) adds separate revenue not broken out publicly.

### Estimated Forge MRR (inference)

Using **~27,000 customers** and blended ARPU ($15–$25/mo depending on Hobby vs Growth vs Business mix):

| Blended ARPU | Est. MRR | Est. ARR |
|---:|---:|---:|
| $15/mo | ~$405k | ~$4.9M |
| $19/mo | ~$513k | ~$6.2M |
| $22/mo | ~$594k | ~$7.1M |
| $25/mo | ~$675k | ~$8.1M |

**Planning range:** roughly **$400k–700k/month** (~**$5M–8M/year**) for Forge panel subscriptions alone, before VPS upsell.

### vs Dply Standard

| Customer type | Forge | Dply Standard |
|---|---|---|
| 1 small server | $12–19/mo | ~$17/mo ($15 + XS) |
| 1 mid server | $12–19/mo | ~$25/mo ($15 + M) |
| 10 mid servers | **$19–39/mo** (Growth/Business) | **~$115/mo** ($15 + 10×$10) |

Forge wins on **price for large BYO fleets** (unlimited servers on Growth). Dply wins on **revenue per heavy BYO customer** with base + per-server tiers.

---

## Ploi.io

**Source:** [Ploi pricing](https://ploi.io/pricing); [2025 recap](https://ploi.io/news/recap-ploi-2025) (800+ Ploi Wrapped users); founder posts (no MRR disclosed). Bootstrapped since 2018; also runs **[Ploi Cloud](https://ploi.cloud/pricing)** (usage-based, separate revenue stream).

### Pricing model

Plan caps — flat monthly, not per-server tiering:

| Plan | USD/mo | Servers |
|---|---:|---|
| Free | $0 | 1 |
| Basic | $10 | Up to 5 |
| Pro | $16 | Up to 10 |
| Unlimited | $36 | Unlimited |

5-day Pro trial; annual ~10% off; lifetime deals (custom). Does not include provider/VPS fees.

### Public signals (not revenue)

- **800+** panel users created “Ploi Wrapped” in 2025 (engaged users, not total or paying count)
- **~97** Trustpilot reviews
- Founder has **never published MRR**

### Estimated Ploi MRR (inference)

Assuming **~$14–18 ARPU**/paying org and a bootstrapped Forge-class niche after ~8 years:

| Scenario | Paying customers (guess) | Est. MRR |
|---|---:|---:|
| Low | 400–700 | ~$6k–12k |
| Mid | 1,000–2,000 | ~$15k–35k |
| High | 2,500–4,000 | ~$40k–70k |

**Planning range:** roughly **$10k–40k/month** (~**$120k–480k/year**) for the classic Ploi panel, **plus** unknown Ploi Cloud + lifetime sales.

### vs Dply Standard

| Customer type | Ploi Unlimited | Dply (10 M servers) |
|---|---:|---:|
| Platform fee | **$36/mo** | **~$115/mo** |

Ploi undercuts Dply on **large fleets** at the top plan. Dply scales with server count and size.

---

## Competitor comparison summary

| | **Laravel Forge** | **Ploi** | **Dply Standard** |
|---|---|---|---|
| **Known scale** | ~27k customers (leadership) | Unknown; 800+ Wrapped users | Pre-revenue / early |
| **Est. MRR** | ~$400k–700k | ~$10k–40k | See projections above |
| **Pricing model** | $12 / $19 / $39 flat | $0 / $10 / $16 / $36 flat | $15 base + $2–40/server tier |
| **Large fleet economics** | Cheap (unlimited on Growth) | Cheap (Unlimited $36) | Higher ARPU, scales with fleet |
| **Managed hosting upsell** | Laravel VPS, Cloud | Ploi Cloud | Cloud, Edge, Serverless |

### Strategic takeaway for Dply

- **Indie / 1–3 server** customers: price-sensitive; Forge Growth ($19) and Ploi Pro ($16) set the anchor. Dply at ~$25–45 for small teams is competitive if product depth justifies it.
- **Agency / 10+ server** customers: Forge and Ploi stay flat; Dply’s tier model earns ** materially more** per org — target teams that outgrow unlimited flat plans or need multi-product (Edge, Cloud) in one org.
- **Revenue ceiling:** Forge’s ~27k × ~$20 suggests a mature BYO panel alone can reach **~$500k+ MRR** with a dominant framework brand; Ploi shows a long-tail bootstrapped floor in the ** tens of thousands MRR** range.
