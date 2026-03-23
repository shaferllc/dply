# ADR-006: Phased migration — BYO keeps shipping; feature flags

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted   |
| **Date**     | 2026-03-23 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) §9, §10 |

## Context

A **big-bang rewrite** (freeze BYO, rebuild, swap) risks revenue, trust, and team morale. The platform plan instead uses **ordered phases** (A→G) and **multiple deployables**, while the current **BYO product** must remain **releasable** throughout.

We also need **feature flags** for safe incremental rollout—but flags must not become an excuse to **merge multiple products into one UI** (that direction was explicitly rejected in the plan).

## Decision

### 1. Strict phases (sequence and gates)

- Follow **[§9 Phased migration](../MULTI_PRODUCT_PLATFORM_PLAN.md#9-phased-migration)** order. **Do not skip** a phase whose exit criteria underpin the next (e.g. do not start **Phase C** before **Phase B** deploy-engine seams exist).
- Each phase closes with **documented exit criteria** in the plan; add a short **phase note** in the PR or release notes when crossing a gate (“Phase B complete: factory used everywhere”).
- **Work in vertical slices** inside a phase: each merge request should leave **main** in a **deployable** state for BYO (see below).

### 2. BYO keeps shipping

| Rule | Practice |
| ---- | -------- |
| **Mainline is production-shaped** | `main` (or your release branch) must always pass CI and be **safe to deploy** to BYO production unless explicitly marked otherwise (rare hotfix branch). |
| **Refactor ≠ stop shipping** | Internal extractions (packages, `DeployEngine` wrapper) preserve **observable behavior** for customers; use characterization tests or staging checks when behavior is fragile. |
| **Avoid long-lived integration branches** | Prefer **trunk-based** flow: merge small PRs behind flags or dead-code paths that default to current behavior. |
| **New products are additive** | Serverless/Cloud/Edge land as **new apps** + **new domains**; they do not block BYO releases on the same train unless shared infra is broken (then fix infra, not hide behind one release). |
| **Migrations are BYO-scoped until split** | Schema changes run on **BYO DB only** with backward-compatible steps where needed (`expand → migrate → contract`). |

### 3. Feature flags (when and how)

**Use flags for:**

- **Incomplete** user-visible flows (e.g. Serverless alpha UI, experimental engine path).
- **Gradual rollout** (percentage, allowlist, internal-only).
- **Kill switch** for a risky path without redeploying (short-term).

**Do not use flags for:**

- Permanently toggling **which product** a customer uses inside one binary (that is **multiple SKUs in one app**—contradicts separate deployables).
- Hiding **unfinished core** BYO behavior from production for weeks (fix or revert; do not leave customers on a dead flag path).

**Implementation (Laravel):**

- Prefer **[Laravel Pennant](https://laravel.com/docs/pennant)** (or equivalent) with **named features** and explicit **default = off** for risky behavior.
- Naming: `feature.{area}.{name}` e.g. `serverless.deploy_ui_alpha` — **product-prefixed** when the flag is for a future line, not generic `new_stuff`.
- **Retire flags** after GA: ticket or checklist item to remove branch and delete flag definition (avoid **flag soup**).
- **Ops:** document which flags are **on** in staging vs prod in runbook or env template comments.

**Infrastructure “flags”** (separate from product UX):

- Env vars such as `QUEUE_WORKERS=byo-only` are **deploy-time configuration**, not customer feature flags—allowed per plan §85 (infra, not merged customer UI).

## Consequences

### Positive

- BYO revenue and users are protected during years-long platform evolution.
- Phases stay **auditable**; less temptation to “just ship everything.”

### Negative

- Discipline cost: PR review must ask **“does main still ship BYO?”** and **“flag retirement planned?”**

## Related

- [ADR-001: dply-core boundaries](./0001-dply-core-boundaries.md) — flags stay **app-local**, not core bloat.
- [ADR-004: engine isolation](./0004-engine-isolation-ssh-leakage.md) — flags must not route Edge traffic through SSH engines.
- [ADR-005: database per deploy](./0005-database-per-product-deploy.md) — new apps = new DB, not a flag on BYO’s DB.

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
