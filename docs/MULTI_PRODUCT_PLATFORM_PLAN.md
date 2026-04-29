# dply multi-product platform plan

This document is the working blueprint for evolving the current **bring-your-own-server (BYO)** product into multiple product lines (Serverless, Cloud, WordPress, Edge) sharing one engineering platform.

> **2026-04-28 update:** The earlier *separate-app-per-product* and *separate-DB-per-product* directions were retired. The platform now ships from a **single Laravel application at the repository root** with **one database**. Future product lines re-enter as **modules in the same app**, not as new Laravel installs. Sections below have been updated; cross-references to the old `apps/*` layout (formerly `apps/dply-cloud`, `apps/dply-wordpress`, `apps/dply-edge`, `apps/dply-auth`) are historical.

**Status:** planning — implementation follows the phased migration at the end.

### Current focus (engineering & docs)

**BYO first:** Local onboarding, shipping, and operator documentation target the **repository-root** Laravel app. Use **[BYO local setup](BYO_LOCAL_SETUP.md)** as the canonical getting-started guide. Serverless, Docker, and Kubernetes engines already live alongside the BYO engine in [`app/Services/Deploy/`](../app/Services/Deploy/). Cloud, WordPress, and Edge will be added as additional engines/modules in the same root app when their behavior is real.

### Locked decisions


| Decision          | Choice                                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------------------------- |
| **Data**          | **One database** for all product lines. (The earlier "separate DB per product" rule is retired; if data isolation is needed later, use separate Laravel database connections within the same app.) |
| **Code**          | **One Laravel application** at the repository root, plus the shared **`packages/dply-core`** library.    |
| **Rollout order** | **1 BYO → 2 Serverless → 3 Cloud → 4 WordPress → 5 Edge** (engines/modules inside the same app).         |

**Domains:** The example hostnames in [§1](#1-vision-five-products) and [§8](#8-infrastructure-and-operations) remain **provisional** and can change later. Multiple brands/domains do not require separate Laravel apps — host-based routing or separate route groups inside the root app handle that.

---

## Table of contents

1. [Vision: five products](#1-vision-five-products)
2. [Current state](#2-current-state) — [BYO org vs user scope](#byo-product-1-organization-vs-user-scope)
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


| Order | Product             | Domain (example)          | Customer promise                                                 | Primary runtimes / workloads                                              |
| ----- | ------------------- | ------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------- |
| **1** | **dply** (BYO)      | e.g. `dply.io`            | Your servers; we orchestrate deploys, nginx, SSL, etc.           | Any workload on **customer VMs** (SSH)                                    |
| **2** | **dply Serverless** | e.g. `serverless.dply.io` | Functions and event-driven workloads on **your choice of cloud** | FaaS roadmap: **AWS Lambda**, **Azure Functions**, **Google Cloud Functions**, **Cloudflare Workers**, **Netlify Functions**, **Vercel Functions**, **[DigitalOcean Functions](https://www.digitalocean.com/products/functions)**, and more (see [§6](#6-providers-especially-serverless)) |
| **3** | **dply Cloud**      | e.g. `cloud.dply.io`      | We host **long-lived** apps on our platform                      | **PHP** (Laravel, Symfony, …), **Rails**                                  |
| **4** | **dply WordPress**  | e.g. `wp.dply.io`         | Managed WordPress                                                | WP core, themes, plugins, staging, backups                                |
| **5** | **dply Edge**       | e.g. `edge.dply.io`       | **Vercel-class** DX: git, previews, static + JS                  | **JavaScript** frameworks (Next, Nuxt, Astro, …), **static sites**        |


**Implementation sequence** matches the **Order** column: ship and harden each line before starting the next (overlap in engineering is fine; **priority** is fixed).

### Positioning notes

- **Cloud vs Edge:** Cloud is **PHP/Rails** (FPM, Puma-style processes, traditional servers). Edge is **JS + static** (build → CDN + edge/serverless handlers, PR previews). Deliberately **not** “Rails on Edge” as the default story; hybrid stories (e.g. Rails API + Next frontend) are **two projects** or documented patterns.
- **Serverless vs Edge:** Serverless is **multi-provider, power-user** FaaS (customer-linked cloud accounts; pick Lambda, Azure, GCP, …). Edge is **opinionated**: abstract the cloud; optimize for **framework + git + previews**. **Netlify** and **Vercel** sit on the boundary (git-native serverless/edge functions)—we may expose them from **Serverless** (BYO project + token), **Edge** (opinionated ship), or both with clear SKUs; roadmap lists them under Serverless until product packaging is fixed.
- **Multi-cloud support** for Serverless is **adapters inside one product**, not one business per cloud.

---

## 2. Current state

- Single Laravel application focused on **organizations, servers, sites** with `server_id` and **SSH** (`SshConnection`, provisioners, queued jobs).
- Deploy and infra flows are **VM-shaped** (nginx, SSL, git on remote disk, long-running queue workers).
- This codebase remains the **foundation of product 1 (BYO)** and must keep working for existing customers during migration.

### BYO (product 1): organization vs user scope

**Organization (and billing)**  
**Subscription, plan limits, and any org-level quotas** (e.g. servers today; optional member seats; any future **site count** caps we add) apply to the **entire organization**—**all servers and all sites** under that org share the same envelope. We do **not** model **per-site SKUs** or per-site billing unless we deliberately introduce that as a separate product decision.

**User (identity and account settings)**  
**Profile, password, email verification, two-factor authentication (2FA), and OAuth-linked social accounts** are **user-scoped**: they live on the **user** record, not on an organization or a site. The same person keeps the same sign-in and security posture when switching org context or when working on **any site** their membership allows. Do not fork 2FA/OAuth/profile per org unless we add an explicit exception (e.g. enterprise SSO later).

**Teams (today)**  
**Teams** group **servers** inside an org; they are **not** a second layer for billing or for duplicating user security settings.

---

## 3. Target architecture

### Single Laravel app, single database (locked)

- **One repository, one Laravel application** at the root. The shared **`packages/dply-core`** library stays.
- **One database** holds every product line's tables. Cross-product reporting and unified dashboards work without a synthesis layer because there is only one transactional store.
- **Multiple brands/domains** are served by host-based routing or separate route groups within the same app — not by spinning up a new Laravel install.
- **Identity is unified by default** (one login across all product lines). Multi-account stories or external SSO are additive later, not foundational.

### Principles

1. **One deployable** for all product lines. Scale individual concerns (queues, workers) by **horizontal worker pools**, not by forking the app.
2. **Shared packages** (`dply-core`, etc.) for stable, low-churn code: signing, tokens, audit helpers, interfaces — not product-specific Livewire trees or product-specific migrations.
3. **Execution via engines:** each product type implements a **deploy/provision contract** in [`app/Services/Deploy/`](../app/Services/Deploy/) (today: BYO, AWS Lambda, DigitalOcean Functions, Docker, Kubernetes). New product lines add new engines, not new apps.
4. **Rollout order:** BYO first (live), then Serverless (live), Cloud, WordPress, Edge — see [§9](#9-phased-migration).

### What "separate apps" became

Early discussion considered separate Laravel apps per product line (`apps/dply-cloud`, etc.). The chosen direction is a **single app with multiple engines and modules**: brand boundaries are about **routing, UX, and pricing**, not codebase boundaries.

---

## 4. Execution backends (engines)

Abstract **how** a deployment runs without forcing one data model for all products.

Suggested concepts (names are indicative):


| Concept             | Responsibility                                                                                            |
| ------------------- | --------------------------------------------------------------------------------------------------------- |
| **Deploy engine**   | Given project + git ref (or artifact) + environment: run build/publish, append logs, set terminal status. |
| **Runtime adapter** | Attach routes, domains, SSL, scaling—product- and provider-specific.                                      |
| **Build runner**    | Ephemeral environment: clone, install, compile (BYO: remote shell; Cloud/Edge/Serverless: our workers).   |


**Concrete engine families** (build order follows [§1](#1-vision-five-products)):


| Engine                   | Product (order) | Mechanism                                                            |
| ------------------------ | --------------- | -------------------------------------------------------------------- |
| `ByoServerDeployEngine`  | dply BYO (1)    | SSH + existing jobs/services                                         |
| `ServerlessDeployEngine` | Serverless (2)  | Provider adapters — see **FaaS roadmap** in [§6](#6-providers-especially-serverless) |
| `CloudDeployEngine`      | Cloud (3)       | Managed PHP/Rails (containers/VMs—implementation TBD)              |
| `WordPressDeployEngine`  | WordPress (4)   | WP-specific images, wp-cli, file/DB sync                             |
| `EdgeDeployEngine`       | Edge (5)        | Framework builds, static to object storage, edge functions, previews |


Engines share **patterns** (job dispatch, deployment records, notifications), not necessarily one mega-class.

---

## 5. Data model direction

### Problem

A single `sites` row tied to `server_id` cannot cleanly represent Edge previews, Lambda ARNs, and WP multisite in one table without constant nullable columns and confusion.

### Direction

Use a **project** (or **application**) entity as the control-plane unit, with a `product` (or `line`) discriminator and **product-specific child records** or **JSON `config`** for fields that only apply to one line (`server_id` for BYO, function ARN for Serverless, etc.).

- One `projects` table, one `deployments` table, both keyed to the product line.
- Product-specific child records or JSON for shape that does not generalize.
- Engines write deployment status into the same shared tables.

**Migration:** evolve the current `sites` / related tables into this shape inside the single root database — see [adr/0003-projects-table-migration-from-sites.md](adr/0003-projects-table-migration-from-sites.md).

### Cross-product linking

With one shared database, cross-product reporting and linked-account stories are direct foreign keys or joins. Identity is unified by default; external SSO is additive later.

---

## 6. Providers (especially serverless)

- **dply Serverless** exposes **many providers** as **per-project or per-deployment settings** (customer credentials / linked accounts — never one shared “dply mega-account” for all tenants in v1 unless explicitly designed).
- Implement **one interface per capability** (e.g. deploy function revision, bind HTTP route, set env, tail logs) with **provider-specific adapters**.
- **Hosting our Serverless Laravel app on AWS** (control plane) is a separate decision from **customer** FaaS: compare **[Bref](https://bref.sh/)** (open-source Lambda + `bref/laravel-bridge`) vs **[Laravel Vapor](https://vapor.laravel.com/)** (first-party managed Lambda product) — see [serverless-laravel-aws-hosting.md](./serverless-laravel-aws-hosting.md).
- **dply Edge** may still use multiple providers **under the hood**; the customer-facing promise is **not** “pick Lambda vs Cloud Run” but **“connect repo and ship.”**

### Serverless FaaS provider roadmap (customer execution targets)

These are **targets for `ServerlessFunctionProvisioner` / provider gateways** in the **repository-root app** (and shared contracts later), not a commitment to ship all at once. Order is **indicative** (AWS first in-repo; then expand by demand and credential model).

| Provider | Platform | Roadmap notes |
| -------- | -------- | ------------- |
| **Amazon** | **AWS Lambda** | **In progress:** SDK describe + optional zip `UpdateFunctionCode`; S3/large artifacts, layers, aliases later. |
| **Microsoft** | **Azure Functions** | Roadmap: Azure SDK / REST, function app + deployment slots, storage for packages. |
| **Google** | **Google Cloud Functions** | Roadmap: Cloud Functions / Cloud Run overlap — pick primary API per stack (2nd gen CF vs Run for containers). |
| **Cloudflare** | **Cloudflare Workers** | Roadmap: Workers API, wrangler-compatible flows or direct API; Durable Objects / KV out of initial scope unless needed. |
| **Netlify** | **Netlify Functions** | Roadmap: Netlify API + build/deploy hooks; overlaps **Edge**-style git UX — align SKU with [§1 positioning](#1-vision-five-products). |
| **Vercel** | **Vercel Functions** | Roadmap: Vercel API / deployments; strong overlap with **dply Edge** — same SKU alignment note as Netlify. |
| **DigitalOcean** | **[DigitalOcean Functions](https://www.digitalocean.com/products/functions)** (App Platform–integrated serverless; Node, Python, Go, PHP, etc.) | **In progress:** OpenWhisk-style REST zip action behind env flags; refine against live DO API as needed. |

**Cross-cutting:** each row eventually needs **auth** (OAuth, API tokens, IAM roles), **artifact pipeline** (zip, container image, or provider-native build), and **observability** (logs/metrics hooks) — shared patterns, separate adapter implementations.

---

## 7. Repository layout

Current structure:

```text
dply/
├── composer.json          ← root Laravel app (all product lines)
├── app/, routes/, ...
└── packages/
    └── dply-core/           ← shared library (Composer path repo)
```

The single root app holds:

- `routes/`, Livewire components, Blade views, marketing pages
- One `.env`, one `DB_*` config
- Deploy engines for every product line: BYO (SSH), AWS Lambda, DigitalOcean Functions, Docker, Kubernetes, with Cloud / WordPress / Edge re-entering as new engines + modules when their behavior is real
- One queue surface; isolate hot workloads (e.g. Edge builds, BYO SSH jobs) by **separate queues / worker pools**, not separate Laravel apps

See [MONOREPO_AND_APPS.md](MONOREPO_AND_APPS.md) for the install and deploy details.

**History:** earlier iterations of this plan called for separate `apps/dply-{cloud,wordpress,edge,auth}` Laravel installs with separate databases. That layout was removed on 2026-04-28; everything ships from the root app.

---

## 8. Infrastructure and operations

- **Queues:** separate queues (or prefixes) per product line within the same app; cap concurrency per engine type.
- **Workers:** BYO needs reliable SSH egress; Edge needs **high CPU/RAM build** workers; Serverless needs **credential-scoped** access to customer clouds (or dedicated subaccounts). All three can be horizontal worker pools of the same root app subscribed to different queues.
- **Secrets:** isolate IAM / provider API keys by purpose (BYO server providers vs serverless gateways vs Git OAuth) where blast radius differs.
- **Observability:** per-product-line dashboards for deploy volume, failure rate, p95 build time, keyed on the product/line column.
- **Billing:** Stripe products / meters per line (invocation-based vs flat tier vs seat-based).

### Database

The root app uses one database. Standard Laravel `DB_*` env vars in a single `.env`. If a future product line needs data isolation, add a **named Laravel connection** in [`config/database.php`](../config/database.php) (e.g. `pgsql_edge`) rather than spawning a new Laravel app.

**Ops checklist:** verify the deploy target's `DB_DATABASE` and `APP_KEY` per environment (staging/prod), audit Horizon/queue worker pools for the right queue mix.

---

## 9. Phased migration

Work proceeds in order; skipping **Phase B** before **C** tends to double migrations.

### Phase A — Platform kernel (no new customer products)

- Identify **shared** vs **BYO-only** code; move pure helpers to a package boundary (even if still in-repo path alias first).
- Document **deployment lifecycle** states and events (queue → build → publish → active/failed).
- Optional: centralize webhook verification and API token patterns used across future apps.

**Exit criteria:** Clear internal boundaries; no change to customer-facing SKUs.

### Phase B — Deploy engine abstraction (still one app)

- [x] **`DeployEngine`** + **`DeployEngineResolver::forProject(Project)`** (and **`forSite`** → project); **`ByoDeployContext`** carries **`Project`**; **`ByoServerDeployEngine`** resolves **`Site`** for SSH/git.
- [x] Wrap existing SSH pipeline in **`ByoServerDeployEngine`**; **`RunSiteDeploymentJob`** uses **`forProject`**.
- No second product yet—only **pluggability**.

**Exit criteria:** Adding a new engine does not require shotgun edits across controllers.

### Phase C — Data model (BYO database only, first)

- [x] Introduce **`projects`** and **`sites.project_id`** / **`site_deployments.project_id`** (migration + `Project` model + site lifecycle) — **first slice shipped** in BYO app.
- Enforce **`server_id`** and BYO-specific fields only on BYO models (still on **`sites`**).
- Do **not** design one mega-schema for all five products in one database—other products get **their own migrations** in **their** apps when those apps spin up.

**Exit criteria:** BYO schema is clean and engine-ready; no dependency on other products’ tables.

### Phase D — dply Serverless (second product)

- [x] Serverless engine merged into the **repository-root app**; reusable contracts, adapters, and docs now live there.
- [x] **`DeployEngine`** + **`ServerlessDeployContext`** + **`ServerlessDeployEngine`** + **`DeployEngineResolver`** (stub provisioner); **`/internal/spike`** exercises the engine path.
- [x] **Stub provisioners** for **`local`**, **`aws`**, **`digitalocean`** with **`SERVERLESS_PROVISIONER`** / **`config/serverless.php`** binding.
- [x] **AWS SDK slice (optional):** **`aws/aws-sdk-php`**; **`SERVERLESS_AWS_USE_REAL_SDK`** + **`SERVERLESS_PROVISIONER=aws`** → **`AwsSdkLambdaGateway`** (**GetFunction**; **`UpdateFunctionCode`** from local zip and/or **`s3://`** when **`SERVERLESS_AWS_S3_ALLOW_BUCKETS`**) with bucket/key validation.
- [x] **AWS hosting options doc:** [serverless-laravel-aws-hosting.md](./serverless-laravel-aws-hosting.md) — Bref vs Laravel Vapor for running the merged serverless engine on Lambda (vs customer function deploys).
- [x] **Bref in app:** **`bref/bref`** + **`bref/laravel-bridge`** (^3) for Laravel/PHP serverless deploy support in the repository-root app.
- [x] **Minimal API + webhook + queue:** `POST /api/webhooks/serverless/deploy` (HMAC, **`dply-core` `WebhookSignature`**), `POST /api/serverless/deploy` (Bearer **`SERVERLESS_API_TOKEN`**), **`RunServerlessFunctionDeploymentJob`**, **`serverless_function_deployments`** table.
- [x] **Serverless projects (control plane):** **`serverless_projects`** (`name`, `slug`); **`serverless_function_deployments.serverless_project_id`** optional FK; JSON **`project_slug`** on deploy; **`POST/GET /api/serverless/projects`** (Bearer).
- [x] **FaaS roadmap stub drivers:** **`SERVERLESS_PROVISIONER`** **`azure`**, **`gcp`**, **`cloudflare`**, **`netlify`**, **`vercel`** → shared **`RoadmapStubProvisioner`** when real APIs are off. See [§6](#serverless-faas-provider-roadmap-customer-execution-targets).
- [x] **Live FaaS SDK/API adapters (partial):** **AWS** (SDK), **Cloudflare Workers**, **Netlify** (zip deploy), **Vercel** (zip → deployments), **DigitalOcean** (OpenWhisk-style action update). **Azure** and **GCP** remain roadmap stubs; AWS optional follow-ups (larger artifacts, IAM split in prod).
- [x] **UI + feature flags (Pennant):** `GET /serverless` (safe overview); **`SERVERLESS_INTERNAL_SPIKE`** gates **`GET /internal/spike`**; **`SERVERLESS_PUBLIC_DASHBOARD`** gates the overview; `PENNANT_STORE` + **`features`** migration for persisted overrides. Subdomain routing deferred until GA DNS work.

**Exit criteria:** Serverless product deployable on its own domain with its own DB; uses shared packages from monorepo.

### Phase E — dply Cloud (third product)

> **Reset (2026-04-28):** the earlier `apps/dply-cloud` spike was deleted along with the rest of the multi-app layout. Cloud re-enters as a new engine + module inside the root app when build/publish behavior is real.

- [ ] **`CloudDeployEngine`** + **`CloudDeployContext`** in [`app/Services/Deploy/`](../app/Services/Deploy/) alongside the existing engines.
- [ ] Project + deployment surface using the shared `projects` / deployments tables with a `cloud` discriminator.
- [ ] Real build/publish (containers/VMs, git clone/build, runtime adapter — implementation TBD).

**Exit criteria:** Happy-path deploy for at least one stack (e.g. Laravel or Rails) on managed Cloud.

### Phase F — dply WordPress (fourth product)

> **Reset (2026-04-28):** the earlier `apps/dply-wordpress` spike was deleted along with the rest of the multi-app layout. WordPress re-enters as a new engine + module inside the root app.

- [ ] **`WordpressDeployEngine`** in [`app/Services/Deploy/`](../app/Services/Deploy/) plus a `LocalHostedWordpressProvisioner` (hosted-only; see [ADR-007](adr/0007-wordpress-hosted-runtime-provisioning.md)).
- [ ] WordPress project + deployment surface on the shared tables.

**Exit criteria:** Managed WP path documented and shippable for a narrow MVP (real core/plugins/backups still TBD).

### Phase G — dply Edge (fifth product)

> **Reset (2026-04-28):** the earlier `apps/dply-edge` spike was deleted along with the rest of the multi-app layout. Edge re-enters as a new engine + module inside the root app.

- [ ] **`EdgeDeployEngine`** in [`app/Services/Deploy/`](../app/Services/Deploy/) and Edge project/deployment surface on the shared tables.
- [ ] Real builds/CDN/previews.

**Exit criteria:** Vercel-class MVP for at least one framework (e.g. Next or static + functions).

### Cross-cutting: domains and workers

- As each product line goes live: route **DNS** to the same root app via host-based routing or an upstream proxy; isolate hot workloads with **separate queues / worker pools**, not separate Laravel apps.
- Revisit **package boundaries** after each phase so `dply-core` stays small and stable.

---

## 10. Risks and decisions


| Risk                         | Mitigation                                                              |
| ---------------------------- | ----------------------------------------------------------------------- |
| Big-bang rewrite             | **[ADR-006: phases + BYO shipping + flags](adr/0006-phased-delivery-byo-shipping-flags.md)** — gated §9 phases, trunk-based BYO, Pennant-style flags with retirement. |
| Engine leakage (SSH in Edge) | **[ADR-004: engine isolation](adr/0004-engine-isolation-ssh-leakage.md)** — code review + **queue separation** + adapter-only provider code + CI forbidden-import checks. The boundary is now between engines inside the same app, not between apps. |
| Scope creep on "core"        | **[ADR-001: dply-core boundaries](adr/0001-dply-core-boundaries.md)** — small, stable surface; default "no" to new core code. |


**Open decisions** (record answers as ADRs when resolved):

- Whether to introduce **named Laravel database connections** per product line for storage isolation while staying on one app.
- Identity model evolution: when (if ever) to introduce external SSO or multi-tenant linked accounts on top of the unified user table.

**Resolved (2026-04-28):** Single Laravel app + single database. Per-product Laravel apps and per-product databases (the previous `apps/dply-{cloud,wordpress,edge,auth}` direction) were retired. **[ADR-005: DB per deploy](adr/0005-database-per-product-deploy.md)** is superseded.

---

## 11. Getting started checklist

Use this as the first sprint backlog after buy-in:

1. [x] Approve this plan — **domains** OK for now (placeholders; may change later); product split and rollout order unchanged.
2. [x] Write **ADR-001:** what may live in `dply-core` (boundaries) — [adr/0001-dply-core-boundaries.md](adr/0001-dply-core-boundaries.md).
3. [x] Write **ADR-002:** deploy engine interface + first implementation (BYO wrapper) — [adr/0002-deploy-engine-interface-byo-wrapper.md](adr/0002-deploy-engine-interface-byo-wrapper.md).
4. [x] Write **ADR-003:** `projects` table + migration from `sites` (**BYO database only**) — [adr/0003-projects-table-migration-from-sites.md](adr/0003-projects-table-migration-from-sites.md).
5. [x] Create **`packages/dply-core`** (`shaferllc/dply-core`) and wire **BYO** `composer.json` (path + optional VCS); first utility: `Dply\Core\Security\WebhookSignature` (used by `SiteWebhookSignatureValidator`).
6. [x] **`DeployEngine`** seam + **`ByoServerDeployEngine`** + **`DeployEngineResolver`**; **`RunSiteDeploymentJob`** delegates git/SSH work to the engine (webhook/API/UI unchanged; ADR-002 Phase B).
7. [x] Spike and later merge the serverless engine into the repository-root app, keeping Composer **`shaferllc/dply-core`** via **path** (`packages/dply-core`).
8. [x] **`ServerlessFunctionProvisioner`** + **`LocalStubProvisioner`** (no cloud); `/internal/spike` + feature test (gate/remove before production).
9. [x] **Implement ADR-003 (BYO):** migration `2026_03_26_100000_create_projects_link_sites_and_deployments`, **`Project`** model, **`Site`** creating/finalizing project + **`deleted`** cleanup, **`RunSiteDeploymentJob`** sets **`project_id`** on deployments.
10. [x] **Deploy engine → `Project`:** **`ByoDeployContext`** uses **`Project`**; **`DeployEngineResolver::forProject`**, **`ByoServerDeployEngine`** loads **`site`**; job bails if site has no project.
11. [x] **Serverless deploy seam:** **`DeployEngine`**, **`ServerlessDeployContext`**, **`ServerlessDeployEngine`**, resolver; **`/internal/spike`** uses engine.
12. [x] **Serverless stub providers:** **`LocalStubProvisioner`**, **`AwsLambdaStubProvisioner`**, **`DigitalOceanStubProvisioner`**, **`RoadmapStubProvisioner`** (azure / gcp / cloudflare / netlify / vercel); **`SERVERLESS_PROVISIONER`** selects binding.
13. [x] **Docs:** [serverless-laravel-aws-hosting.md](./serverless-laravel-aws-hosting.md) — Bref vs Laravel Vapor; Laravel **13** + `bref/laravel-bridge` **v3** note.
14. [x] **Serverless API/webhook:** `/api/webhooks/serverless/deploy`, `/api/serverless/deploy`, queued **`RunServerlessFunctionDeploymentJob`**, deployments persistence.
15. [x] **Serverless `serverless_projects` + `project_slug` on deploy; project CRUD + show (latest deployment).**
16. [x] **Serverless AWS SDK:** **`AwsLambdaGateway`**, **`AwsSdkLambdaGateway`**, **`AwsLambdaSdkProvisioner`**; **`SERVERLESS_AWS_USE_REAL_SDK`**.
17. [x] **Serverless AWS zip:** **`UpdateFunctionCode`** when **`SERVERLESS_AWS_UPLOAD_ZIP`** + **`SERVERLESS_AWS_ZIP_PATH_PREFIX`** sandbox.
18. [x] **Roadmap:** §6 FaaS provider table (Lambda, Azure, GCP, Cloudflare Workers, Netlify, Vercel, DO) + Phase D + vision/engine rows.
19. [x] **`apps/dply-cloud`:** Laravel 13 app, **`CloudDeployEngine`** stub + **`/internal/spike`**, **`dply-core`** path repo; see [apps/dply-cloud/README.md](../apps/dply-cloud/README.md).
20. [x] **Cloud deploy API:** **`cloud_deployments`**, queued job → stub engine, list/show deployments, idempotency (parity slice with Serverless).
21. [x] **`apps/dply-wordpress`:** projects + deployments API, stub **`WordpressDeployEngine`**, **`/internal/spike`**; see [apps/dply-wordpress/README.md](../apps/dply-wordpress/README.md).
22. [x] **`apps/dply-edge`:** projects + deployments API, stub **`EdgeDeployEngine`**, **`/internal/spike`**; see [apps/dply-edge/README.md](../apps/dply-edge/README.md).

---

## Document history


| Date       | Change                                            |
| ---------- | ------------------------------------------------- |
| 2026-03-22 | Initial plan from product/architecture discussion |
| 2026-03-22 | Locked: separate DBs + shared codebase; rollout BYO → Serverless → Cloud → WordPress → Edge |
| 2026-03-22 | Added §8 database inventory (env vars + suggested DB names per app) |
| 2026-03-23 | ADR-001 `dply-core` boundaries; checklist renumbered (deploy engine → ADR-002, projects → ADR-003) |
| 2026-03-23 | ADR-004 engine isolation (SSH leakage); risk row links to ADR |
| 2026-03-23 | ADR-005 + runbook: enforce separate `DB_*` per product deploy |
| 2026-03-23 | ADR-006: strict phases, BYO keeps shipping, feature-flag policy |
| 2026-03-23 | ADR-002: deploy engine interface + `ByoServerDeployEngine` wrapper (plan Phase B) |
| 2026-03-23 | ADR-003: `projects` table + migration from `sites` (BYO DB only, Phase C) |
| 2026-03-23 | Checklist: plan approved for execution; example domains provisional (may change later) |
| 2026-03-23 | `packages/dply-core` scaffold + Composer path/VCS; webhook HMAC helper integrated in BYO |
| 2026-03-23 | ADR-002 implemented in BYO: `DeployEngine`, `ByoServerDeployEngine`, resolver; job uses engine |
| 2026-03-23 | Serverless spike started with stub `ServerlessFunctionProvisioner` and `dply-core` path dependency |
| 2026-03-23 | ADR-003 implemented: `projects`, `sites.project_id`, `site_deployments.project_id`, `doctrine/dbal` dev (BYO `composer.json` VCS repo for `dply-core` removed to avoid failed clones) |
| 2026-03-23 | Phase B: `DeployEngine` / `ByoDeployContext` keyed by `Project`; resolver `forProject` + `forSite` |
| 2026-03-23 | BYO `ByoDeployContext`: PHPDoc `{@see DeployEngine}` / `{@see Site}` and import alignment |
| 2026-03-23 | Phase D (slice): Serverless app `DeployEngine` + `ServerlessDeployContext` + `ServerlessDeployEngine` + resolver; `/internal/spike` uses engine |
| 2026-03-23 | Serverless: config-driven stub provisioners `local` / `aws` / `digitalocean` (`SERVERLESS_PROVISIONER`); checklist §11–§12 |
| 2026-03-23 | [serverless-laravel-aws-hosting.md](./serverless-laravel-aws-hosting.md): Bref vs Laravel Vapor; §6 + Phase D + checklist §13 |
| 2026-03-23 | Serverless: `shaferllc/dply-core` **path-only** (removed GitHub VCS repository) |
| 2026-03-23 | Serverless: webhook + Bearer API deploy routes, `RunServerlessFunctionDeploymentJob`, `serverless_function_deployments` |
| 2026-03-23 | Serverless: `serverless_projects`, optional `project_slug` on deploy, `POST/GET /api/serverless/projects` |
| 2026-03-23 | Serverless: `aws/aws-sdk-php`, optional real AWS Lambda GetFunction via `SERVERLESS_AWS_USE_REAL_SDK` |
| 2026-03-23 | Serverless AWS: `AwsLambdaGateway`, optional zip `UpdateFunctionCode` under `SERVERLESS_AWS_ZIP_PATH_PREFIX` |
| 2026-03-23 | Roadmap: §6 FaaS table — AWS Lambda, Azure Functions, GCP Cloud Functions, Cloudflare Workers, Netlify, Vercel, DO; §1/§4/Phase D aligned |
| 2026-03-23 | **`apps/dply-cloud`:** Phase E spike — Laravel 13, `dply-core`, `CloudDeployEngine` stub, gated `/internal/spike`; §7 “today” + Phase E + Phase D adapter row updated |
| 2026-03-23 | §6: link **DigitalOcean Functions** product page (`digitalocean.com/products/functions`) |
| 2026-03-23 | Serverless: **Laravel Pennant** + **`/serverless`** + env-gated **`/internal/spike`**; `features` migration published |
| 2026-03-23 | Serverless: roadmap **stub** provisioners **azure**, **gcp**, **cloudflare**, **netlify**, **vercel** (`RoadmapStubProvisioner`) |
| 2026-03-23 | §2 **BYO scope:** org billing/limits apply to **all sites** in the org; **2FA**, **OAuth**, **profile** are **user-global** |
| 2026-03-23 | **BYO-first focus:** [BYO_LOCAL_SETUP.md](./BYO_LOCAL_SETUP.md); “Current focus” — Serverless/Cloud/WordPress/Edge **on hold** for default onboarding; root README updated |
| 2026-03-25 | Phase F/G: **`apps/dply-wordpress`**, **`apps/dply-edge`** — projects + deployments API, stub engines, §7 "today" + checklist §21–§22 |
| 2026-03-25 | Phase E: **`cloud_deployments`**, **`RunCloudDeploymentJob`**, **`POST /api/cloud/deploy`** + deployment index/show; stub engine unchanged; real build/publish still open |
| 2026-04-28 | **Retired the multi-app + per-product-DB layout.** Deleted `apps/dply-{cloud,wordpress,edge,auth}` and central-auth integration; `config/dply_auth.php` removed; `dply_auth_id` dropped from `users`. Cloud/WordPress/Edge phases reset; they re-enter as engines + modules inside the root app. ADR-005 marked superseded. |


---

## Related docs

- [BYO local setup](./BYO_LOCAL_SETUP.md) — run the root app
- [Monorepo and apps](./MONOREPO_AND_APPS.md) — repository layout, `packages/dply-core` workflow
- [ADR-001: dply-core boundaries](./adr/0001-dply-core-boundaries.md) — scope rules for shared package
- [ADR-002: deploy engine interface + BYO wrapper](./adr/0002-deploy-engine-interface-byo-wrapper.md)
- [ADR-003: projects table + migration from sites (BYO)](./adr/0003-projects-table-migration-from-sites.md)
- [ADR-004: engine isolation / SSH leakage](./adr/0004-engine-isolation-ssh-leakage.md) — queues, adapters, review + CI
- [ADR-005: database per product deploy](./adr/0005-database-per-product-deploy.md) — **superseded 2026-04-28**
- [ADR-006: phased delivery, BYO shipping, feature flags](./adr/0006-phased-delivery-byo-shipping-flags.md)
- [DEPLOYMENT_FLOW.md](./DEPLOYMENT_FLOW.md) — current BYO deploy behavior
- [API.md](./API.md) — existing API surface
- [ORG_ROLES_AND_LIMITS.md](./ORG_ROLES_AND_LIMITS.md) — BYO org roles, deployer rules, Free vs Pro server/site limits (in-app: **Docs → Roles & plan limits**)
- [serverless-laravel-aws-hosting.md](./serverless-laravel-aws-hosting.md) — Bref vs Vapor for the serverless engine on AWS

