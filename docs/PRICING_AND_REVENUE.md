# Dply pricing & revenue projections

As implemented in this repo (`config/subscription.php`, `config/dply.php`, billing services). Amounts are **USD** unless noted.

**Last synced from codebase:** 2026-05-28  
**Pricing model:** flat plans metered by BYO server **count** (Free / Starter / Pro / Business), managed products à la carte.

---

## Overview

Dply uses **flat self-serve plans** priced by the **number of BYO servers** an organization manages, plus **Enterprise** for sales-led deals. Pricing is **organization-scoped**, not per-seat:

- **Unlimited team members**
- **Unlimited sites** (no per-site fee on BYO VMs)
- **Servers** are what the plan is metered on — the plan tier is chosen by the **count** of billable servers (not their size)

You charge for:

1. A flat **plan fee** chosen by billable BYO server count (Free → Business)
2. **Managed products** the org runs à la carte (Cloud, Edge, Serverless) — these require a paid plan
3. Optional **Edge delivery usage** (metered, when enabled)

**Important:** Dply bills for **platform work**, not the customer's cloud invoice. A $5 Hetzner box and a $500 AWS instance pay the **same Dply plan** — what matters is how many servers dply manages, not how big they are. This mirrors the proven Ploi / Forge / RunCloud model and sits inside the $8–39 market cluster.

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

**Free-zone exemption:** an org whose current usage resolves to the **Free** plan and owes **nothing** this cycle is **never paused** — the pause ladder only applies to orgs that actually owe money. A single-server account can live on Free indefinitely without a card.

### Self-serve plans

Metered by **billable BYO server count**. The resolver (`SubscriptionPlanResolver`) picks the cheapest plan whose ceiling covers the count.

| Plan | USD/mo | Servers | Notes |
|---|---:|---|---|
| **Free** | **$0** | 1 | No card. Full product. No managed products. |
| **Starter** | **$9** | Up to 3 | |
| **Pro** | **$19** | Up to 10 | |
| **Business** | **$39** | Unlimited | No server cap |

| | |
|---|---|
| **Billing** | Stripe Checkout + Cashier; monthly or annual |
| **Annual discount** | **20% off** when paid yearly |
| **Feature gating** | None — every plan ships every feature |
| **Age grace** | Servers younger than **1 day** don't count (`SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS`, default `1`) |

**Example monthly totals (before tax):**

| Profile | Servers | Plan | Monthly |
|---|---|---|---:|
| First project | 1 | Free | **$0** |
| Indie / small team | 3 | Starter | **$9** |
| Growing team | 8 | Pro | **$19** |
| Agency / large fleet | 25 | Business | **$39** |

**Example annual (20% off):**

| Plan | Yearly |
|---|---:|
| Starter | **~$86/yr** |
| Pro | **~$182/yr** |
| Business | **~$374/yr** |

### Enterprise

| | |
|---|---|
| **Price** | Custom — negotiated in Stripe (`STRIPE_PRICE_ENTERPRISE`) |
| **Typical adds** | Volume pricing, MSA, SSO, audit logs, dedicated support / SLA |
| **How to buy** | Sales-led; manual Stripe subscription |

---

## BYO servers (VM / SSH-managed)

Ready VM hosts the customer SSHs into count toward the **plan tier**. The plan is chosen by **how many** billable servers the org runs — size is **not** billed (the customer already pays their provider for size).

**Rules:**

- **Count basis:** plan tier = cheapest plan whose ceiling ≥ billable server count
- **Age grace:** servers younger than **1 day** are not counted (`SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS`, default `1`)
- **Status:** must be `ready`
- **Excluded:** dply-managed logical hosts (Cloud, Edge, serverless namespaces) don't count toward the plan tier
- **Provider:** customer pays DigitalOcean / Hetzner / AWS / etc. **directly**

---

## Managed products (flat platform fees, à la carte)

Managed products run on **dply-owned infra**, so they bill per live unit on top of any **paid** plan. They are **not** available on Free — using one requires at least Starter.

| Product | Billable unit | Default fee | Notes |
|---|---|---|---|
| **dply Cloud** | Per live production app | **$5/mo** | Container apps on `dply_cloud`; branch previews excluded |
| **dply Edge** | Per live production site | **$2/mo** | Static/SSG on managed `dply_edge`; branch previews excluded |
| **Serverless** | Per code function | **$2/mo** | Active function sites / code actions |

---

## dply Edge — platform + delivery usage

### Platform fee

**$2/mo per live production Edge site** (requires a paid plan).

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
  plan fee (Free $0 / Starter $9 / Pro $19 / Business $39, by server count)
+ (serverless count × $2)
+ (Cloud apps × $5)
+ (Edge sites × $2)
+ Edge delivery usage (if enabled)
```

Managed products require a paid plan. Stripe requires a **uniform billing interval** per subscription; adding a server that crosses a plan ceiling → Stripe prorates the plan swap.

---

## Trial / access gating

| State | Deploys & scheduler | Agent metrics |
|---|---|---|
| Active trial | ✅ | ✅ |
| Subscribed (paid plan / Enterprise) | ✅ | ✅ |
| Free-zone (owes nothing this cycle) | ✅ | ✅ |
| Soft pause (expired trial, owes money) | ❌ | ✅ |
| Hard pause (owes money) | ❌ | ❌ |

Optional: `DPLY_API_TOKENS_REQUIRE_PAID_PLAN=true` gates **creating new** API tokens behind an active paid plan.

---

## Configuration reference

| Setting | Default | Purpose |
|---|---|---|
| `subscription.standard.plans.free` | $0 / 1 server | Always-free entry plan |
| `subscription.standard.plans.starter` | $9 / ≤3 | Starter plan |
| `subscription.standard.plans.pro` | $19 / ≤10 | Pro plan |
| `subscription.standard.plans.business` | $39 / unlimited | Business plan |
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

In Dply's model, **"users" = organizations** (workspaces). Seats are unlimited, so revenue scales with **orgs × plan tier**, plus managed-product attach.

Figures below are **gross revenue** (before Stripe fees, infra, support, tax, etc.).

---

## Per-org revenue (quick reference)

| Customer type | Plan | **$/mo** | **$/yr** |
|---|---|---:|---:|
| First project | Free (1 server) | **$0** | $0 |
| Indie / small team | Starter (≤3) | **$9** | $108 |
| Growing team | Pro (≤10) | **$19** | $228 |
| Agency / large fleet | Business (unlimited) | **$39** | $468 |
| + Edge | Above + 2 live Edge sites | **+$4** | +$48 |
| + Cloud | Above + 1 Cloud app | **+$5** | +$60 |

**Blended planning estimate:** **~$14–18/org/mo** for paying orgs once you account for a meaningful free-plan base, a Starter-heavy paid mix, and a few Pro/Business fleets — before managed-product attach.

---

## Paying-organization scenarios

"Paying" excludes Free-plan orgs. Blended ARPU below assumes a Starter-heavy mix with some Pro/Business and light managed-product attach.

| Paying orgs | Avg $/org/mo | **MRR** | **ARR** |
|---:|---:|---:|---:|
| 10 | $15 | **$150** | **~$1,800** |
| 100 | $15 | **$1,500** | **~$18,000** |
| 1,000 | $15 | **$15,000** | **~$180,000** |
| 1,000 (Pro-heavy, ~$22) | $22 | **$22,000** | **~$264,000** |

---

## Adjustments that move the forecast

### Free-to-paid conversion

Only orgs that cross **2+ servers** or attach a managed product become paying. The free single-server tier is an acquisition funnel, not revenue — model conversion off the free base, not trials alone.

### Annual billing (−20%)

If roughly half of revenue is on annual plans, effective MRR is about **8–10% lower** than list monthly prices.

### Managed products uplift

Each Edge site (+$2), Cloud app (+$5), or function (+$2) stacks on the plan and requires a paid plan — a strong nudge from Free → Starter. Example: 1,000 paying orgs each attaching 1 Edge site → **+$2k MRR / +$24k ARR**.

### Edge usage billing

High-traffic Edge sites add variable revenue on top of the flat $2/site platform fee.

### Enterprise

A handful of large fleet deals can dominate total revenue vs many small orgs.

---

## Planning formula

```
MRR ≈ Σ (paying orgs × plan fee by server count)
    + (Cloud apps × $5)
    + (Edge sites × $2)
    + (serverless functions × $2)
    + (Edge usage overages, if enabled)
```

**Rule of thumb for 1,000 paying orgs:**

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
| **dply Cloud** | Dply / container backend | $5/app (paid plan required) |
| **dply Edge** | Dply / CF (managed) or customer (BYO CF) | $2/site (+ usage if enabled; paid plan required) |
| **Serverless** | Customer → FaaS provider | $2/function (paid plan required) |

---

# Competitive benchmarks (estimated)

Competitors do **not** publish MRR. Figures below combine **public pricing** with **customer counts or signals** where available. Revenue ranges are **inference**, not verified financials.

Dply now mirrors the flat-rate-by-server-count model these incumbents use, sitting directly inside their price cluster.

---

## Laravel Forge

**Source:** [Forge pricing](https://laravel.com/forge/pricing); Laravel leadership interview (2026); Laravel blog ("tens of thousands of active customers").

| Plan | USD/mo | Servers |
|---|---:|---|
| Hobby | $12 | 1 external (+ unlimited Laravel VPS) |
| Growth | $19 | Unlimited |
| Business | $39 | Unlimited |

~27,000 customers; blended ARPU ~$15–25/mo → planning range **~$400k–700k/month** (~$5M–8M/year) for the panel alone, before VPS upsell.

---

## Ploi.io

**Source:** [Ploi pricing](https://ploi.io/pricing); [2025 recap](https://ploi.io/news/recap-ploi-2025); also runs [Ploi Cloud](https://ploi.cloud/pricing).

| Plan | USD/mo | Servers |
|---|---:|---|
| Free | $0 | 1 |
| Basic | $10 | Up to 5 |
| Pro | $16 | Up to 10 |
| Unlimited | $36 | Unlimited |

Bootstrapped since 2018; MRR never disclosed. Planning range **~$10k–40k/month** for the classic panel, plus Ploi Cloud + lifetime sales.

---

## Competitor comparison summary

| | **Laravel Forge** | **Ploi** | **Dply** |
|---|---|---|---|
| **Pricing model** | $12 / $19 / $39 flat | $0 / $10 / $16 / $36 by count | **$0 / $9 / $19 / $39 by count** |
| **Free tier** | No (Hobby $12) | Yes (1 server) | **Yes (1 server)** |
| **Large fleet economics** | Cheap (unlimited on Growth) | Cheap (Unlimited $36) | Cheap (Business $39) |
| **Managed hosting upsell** | Laravel VPS, Cloud | Ploi Cloud | Cloud, Edge, Serverless (à la carte) |

### Strategic takeaway for Dply

- **Free 1-server tier** matches Ploi's funnel and undercuts Forge's $12 entry — strong top-of-funnel for acquisition.
- **Starter $9 / Pro $19 / Business $39** brackets sit directly inside the Ploi/Forge cluster, so Dply competes on product depth (multi-product: Edge, Cloud, Serverless) rather than price.
- **Managed products** are the differentiated upsell — they require a paid plan, pulling free/Starter accounts upward and adding per-unit revenue on top of the flat plan.
- **Revenue ceiling:** a mature flat-rate panel with a dominant brand can reach **~$500k+ MRR** (Forge); the bootstrapped floor sits in the **tens of thousands MRR** range (Ploi). Dply's edge is bundling BYO + managed hosting in one org.
