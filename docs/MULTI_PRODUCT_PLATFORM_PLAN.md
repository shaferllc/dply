# dply multi-product platform plan

This document is the working blueprint for evolving the current **bring-your-own-server (BYO)** product into **five distinct businesses** under one engineering platform: shared primitives where it helps, separate apps and domains where the customer promise differs.

**Status:** planning — implementation follows the phased migration at the end.

---

## Table of contents

1. [Vision: five products](#1-vision-five-products)
2. [Current state](#2-current-state)
3. [Target architecture](#3-target-architecture)
4. [Execution backends (engines)](#4-execution-backends-engines)
5. [Data model direction](#5-data-model-direction)
6. [Providers (especially serverless)](#6-providers-especially-serverless)
7. [Monorepo layout](#7-monorepo-layout)
8. [Infrastructure and operations](#8-infrastructure-and-operations)
9. [Phased migration](#9-phased-migration)
10. [Risks and decisions](#10-risks-and-decisions)
11. [Getting started checklist](#11-getting-started-checklist)

---

## 1. Vision: five products

Each line is a **separate brand**, **separate domain**, and **separate go-to-market** (pricing, docs, support). One company may operate all five; customers are not assumed to share one login across products unless we add that later on purpose.

| # | Product | Domain (example) | Customer promise | Primary runtimes / workloads |
|---|---------|------------------|------------------|------------------------------|
| 1 | **dply** (BYO) | e.g. `dply.io` | Your servers; we orchestrate deploys, nginx, SSL, etc. | Any workload on **customer VMs** (SSH) |
| 2 | **dply Cloud** | e.g. `cloud.dply.io` | We host **long-lived** apps on our platform | **PHP** (Laravel, Symfony, …), **Rails** |
| 3 | **dply WordPress** | e.g. `wp.dply.io` | Managed WordPress | WP core, themes, plugins, staging, backups |
| 4 | **dply Serverless** | e.g. `serverless.dply.io` | Functions and event-driven workloads on **your choice of cloud** | FaaS on **AWS**, **DigitalOcean**, and **additional providers** over time |
| 5 | **dply Edge** | e.g. `edge.dply.io` | **Vercel-class** DX: git, previews, static + JS | **JavaScript** frameworks (Next, Nuxt, Astro, …), **static sites** |

### Positioning notes

- **Cloud vs Edge:** Cloud is **PHP/Rails** (FPM, Puma-style processes, traditional servers). Edge is **JS + static** (build → CDN + edge/serverless handlers, PR previews). Deliberately **not** “Rails on Edge” as the default story; hybrid stories (e.g. Rails API + Next frontend) are **two projects** or documented patterns.
- **Serverless vs Edge:** Serverless is **multi-provider, power-user** FaaS (pick AWS, DO, …). Edge is **opinionated**: abstract the cloud; optimize for **framework + git + previews**.
- **Multi-cloud support** for Serverless is **adapters inside one product**, not one business per cloud.

---

## 2. Current state

- Single Laravel application focused on **organizations, servers, sites** with **`server_id`** and **SSH** (`SshConnection`, provisioners, queued jobs).
- Deploy and infra flows are **VM-shaped** (nginx, SSL, git on remote disk, long-running queue workers).
- This codebase remains the **foundation of product 1 (BYO)** and must keep working for existing customers during migration.

---

## 3. Target architecture

### Principles

1. **Separate deployables** per product (eventually five Laravel apps or five distinct entrypoints), each with its own routes, UI, env, and scale profile.
2. **Shared package** (`dply-core` or equivalent) for stable, low-churn code: signing, tokens, audit helpers, optional shared types—not product-specific Livewire trees.
3. **Execution via engines:** each product type implements a **deploy/provision contract**; webhooks and APIs dispatch to the right engine.
4. **Separate databases** (or strictly isolated schemas) per product line by default—simpler compliance, pricing, and future M&A or sunsetting of one line.
5. **Shared identity** (one login across products) is **optional** and **later**; do not block on a global `users` table across four DBs on day one.

### What “two apps in one” became

Early discussion considered one env flag switching “server vs serverless.” The chosen direction is **five businesses**: env flags are for **infrastructure** (which workers, which secrets), not for merging customer-facing products into one UI.

---

## 4. Execution backends (engines)

Abstract **how** a deployment runs without forcing one data model for all products.

Suggested concepts (names are indicative):

| Concept | Responsibility |
|---------|----------------|
| **Deploy engine** | Given project + git ref (or artifact) + environment: run build/publish, append logs, set terminal status. |
| **Runtime adapter** | Attach routes, domains, SSL, scaling—product- and provider-specific. |
| **Build runner** | Ephemeral environment: clone, install, compile (BYO: remote shell; Cloud/Edge/Serverless: our workers). |

**Concrete engine families:**

| Engine | Product | Mechanism |
|--------|---------|-----------|
| `ByoServerDeployEngine` | dply BYO | SSH + existing jobs/services |
| `CloudDeployEngine` | dply Cloud | Managed PHP/Rails (containers/VMs—implementation TBD) |
| `WordPressDeployEngine` | dply WordPress | WP-specific images, wp-cli, file/DB sync |
| `ServerlessDeployEngine` | dply Serverless | Provider adapters (Lambda, DO Functions, …) |
| `EdgeDeployEngine` | dply Edge | Framework builds, static to object storage, edge functions, previews |

Engines share **patterns** (job dispatch, deployment records, notifications), not necessarily one mega-class.

---

## 5. Data model direction

### Problem

A single `sites` row tied to `server_id` cannot cleanly represent Edge previews, Lambda ARNs, and WP multisite in one table without constant nullable columns and confusion.

### Direction

Introduce a **project** (or **application**) entity as the **control-plane** unit:

- `product` or `line`: `byo` | `cloud` | `wordpress` | `serverless` | `edge`
- Product-specific **child records** or **JSON `config`** (e.g. `server_id` only when `product = byo`)
- **Deployments** (and steps/logs) keyed to project; engine writes status

**Migration:** existing sites → `product = byo`, preserve `server_id` and current behavior.

### Cross-product linking

Defer **linked accounts** or **unified org** until product strategy requires it; use explicit integration (e.g. OAuth, org linking) rather than implicit shared `users` across DBs.

---

## 6. Providers (especially serverless)

- **dply Serverless** exposes **many providers** (AWS, DigitalOcean, GCP, Azure, Cloudflare, …) as **per-project or per-deployment settings**.
- Implement **one interface per capability** (e.g. deploy function revision, bind HTTP route, set env, tail logs) with **provider-specific adapters**.
- **dply Edge** may still use multiple providers **under the hood**; the customer-facing promise is **not** “pick Lambda vs Cloud Run” but **“connect repo and ship.”**

---

## 7. Monorepo layout

Target structure (illustrative):

```text
apps/
  dply-server/        # Product 1 — BYO (evolution of current app)
  dply-cloud/         # Product 2 — PHP/Rails
  dply-wordpress/     # Product 3
  dply-serverless/    # Product 4
  dply-edge/          # Product 5
packages/
  dply-core/          # Shared library (composer package)
  dply-deploy-contracts/   # Optional: interfaces only, minimal deps
```

Each app has its own:

- `routes/`, Livewire (or front-end), marketing pages
- `.env` / secrets / deploy pipeline
- Queue names and worker pools (isolate heavy Edge builds from BYO SSH jobs)

**Alternative:** a single repo with one Laravel root and **namespaced** routes per domain—possible early on, but **harder** to scale teams and infra; prefer **split apps** before five domains go to production traffic.

---

## 8. Infrastructure and operations

- **Queues:** separate queues (or prefixes) per product; cap concurrency per engine type.
- **Workers:** BYO needs reliable SSH egress; Edge needs **high CPU/RAM build** workers; Serverless needs **credential-scoped** access to customer clouds (or dedicated subaccounts).
- **Secrets:** isolate IAM/API keys per product where blast radius differs.
- **Observability:** per-product dashboards for deploy volume, failure rate, p95 build time.
- **Billing:** separate Stripe products / meters per line (invocation-based vs flat tier vs seat-based).

---

## 9. Phased migration

Work proceeds in order; skipping **Phase B** before **C** tends to double migrations.

### Phase A — Platform kernel (no new customer products)

- [ ] Identify **shared** vs **BYO-only** code; move pure helpers to a package boundary (even if still in-repo path alias first).
- [ ] Document **deployment lifecycle** states and events (queue → build → publish → active/failed).
- [ ] Optional: centralize webhook verification and API token patterns used across future apps.

**Exit criteria:** Clear internal boundaries; no change to customer-facing SKUs.

### Phase B — Deploy engine abstraction (still one app)

- [ ] Define **`DeployEngine` (or equivalent) interface** and factory: `for(Project $project)`.
- [ ] Wrap existing SSH pipeline in **`ByoServerDeployEngine`**; all current entry points use the factory.
- [ ] No second product yet—only **pluggability**.

**Exit criteria:** Adding a new engine does not require shotgun edits across controllers.

### Phase C — Data model: `project` + `product` type

- [ ] Add **`projects`** (or equivalent) with **`product`** enum and migration path from **`sites`**.
- [ ] Backfill: existing data → `product = byo`; enforce **`server_id`** only for BYO.
- [ ] New tables or JSON columns for non-BYO fields—avoid nullable soup on `sites` long term.

**Exit criteria:** Schema can represent all five product types; BYO unchanged in behavior.

### Phase D — Second product (choose one)

Pick **either dply Edge** or **dply Cloud** as the **first** new line (learn build + hosted runtime once).

- [ ] Implement second **`DeployEngine`** + minimal UI + webhook path.
- [ ] Feature-flag or subdomain until GA.

**Exit criteria:** Two products live; two engines; shared kernel in use.

### Phase E — Split deployables (five domains)

- [ ] Extract **`apps/dply-server`** (current product) and add second app from Phase D.
- [ ] Add remaining apps as products reach readiness.
- [ ] Route DNS to correct **document root** / worker fleet; separate queues.

**Exit criteria:** Each brand on its own domain and deploy pipeline.

### Phase F — Remaining products

- [ ] **WordPress:** engine + WP-specific models and operations.
- [ ] **Serverless:** provider interface + AWS + DigitalOcean first adapters; add more providers incrementally.
- [ ] **Edge:** previews, framework detection, CDN integration—iterate toward Vercel-class DX.

---

## 10. Risks and decisions

| Risk | Mitigation |
|------|------------|
| Big-bang rewrite | Strict phases; BYO keeps shipping; feature flags. |
| One giant shared DB | Prefer **per-product DBs** until a concrete need for unified reporting. |
| Engine leakage (SSH in Edge) | Code review + **queue separation** + adapter-only provider code. |
| Scope creep on “core” | ADR: what may live in `dply-core` (small, stable surface). |

**Open decisions** (record answers as ADRs when resolved):

- Second product after BYO: **Edge** vs **Cloud** (growth vs deepest Laravel/Rails fit).
- Monorepo tool (Composer path repos, Nx-style orchestration, CI matrix).
- Whether **orgs** exist only inside each product or a future **global org ID**.

---

## 11. Getting started checklist

Use this as the first sprint backlog after buy-in:

1. [ ] Approve this plan (or revise product names/domains).
2. [ ] Write **ADR-001:** deploy engine interface + first implementation (BYO wrapper).
3. [ ] Write **ADR-002:** `projects` table + `product` enum + migration from `sites`.
4. [ ] Create **`packages/dply-core`** (or `app/Support/Platform` interim) and move one **zero-risk** utility to prove the path.
5. [ ] Refactor **one** webhook path to **`DeployEngine::for($project)`** with BYO only.
6. [ ] Choose **second product** (Edge vs Cloud) and spike **build worker** + **happy-path deploy** (no GA required).

---

## Document history

| Date | Change |
|------|--------|
| 2026-03-22 | Initial plan from product/architecture discussion |

---

## Related docs

- [DEPLOYMENT_FLOW.md](./DEPLOYMENT_FLOW.md) — current BYO deploy behavior
- [API.md](./API.md) — existing API surface (will split or version per product over time)
