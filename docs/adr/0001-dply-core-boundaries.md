# ADR-001: What may live in `dply-core`

| Field        | Value        |
| ------------ | ------------ |
| **Status**   | Accepted     |
| **Date**     | 2026-03-23   |
| **Deciders** | Platform     |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) |

## Context

We plan a **shared Composer package** (`dply-core`) used by multiple product apps (BYO, Serverless, Cloud, …). Without explicit rules, **every** shared urge becomes “put it in core,” which produces a **large, unstable dependency** that every app must absorb. That blocks releases, couples unrelated products, and defeats the goal of **separate databases and separate deployables**.

We need a **small, stable surface**: things that are unlikely to churn weekly and that **do not** encode one product’s UX or schema.

## Decision

**`dply-core` holds only cross-product primitives: contracts, types, and tiny utilities with no framework coupling and no product policy.**

Everything else stays in:

- the **owning product app** (default), or
- a **product-scoped package** (e.g. `dply-byo-deploy`, `dply-edge-build`), or
- a **thin integration package** if two products truly share one integration (rare—justify in a new ADR).

If a change would reasonably be debated in a **product roadmap meeting**, it **does not** belong in `dply-core` without a new ADR that expands the allowed surface.

## Allowed in `dply-core` (IN)

Put code here only if **all** are true:

1. **Multi-product** — Used (or clearly intended) by **two or more** product lines, or it defines a **contract** those lines implement.
2. **Stable API** — You can name the public API and commit to semver-ish compatibility (avoid breaking renames without major version).
3. **Framework-light** — No Livewire, Blade, Filament, HTTP controllers, routes, or Laravel-specific **application** wiring. **Allowed:** plain PHP, small PSR-friendly helpers, optional **interfaces** that Laravel code implements in each app.
4. **No product schema** — No Eloquent models, migrations, or DB assumptions tied to one product’s tables. **Allowed:** DTOs/value objects that are **persistence-agnostic** (e.g. deploy request payload shape) if shared across engines.
5. **No secrets / env coupling** — No direct `env()`, `config()` for product-specific keys, or `.env` contract. Apps inject configuration.

**Examples that fit (when actually shared):**

- **Contracts / interfaces:** `DeployEngine`, `RuntimeAdapter`, `ArtifactStore` (signatures only).
- **Value objects / DTOs:** deployment IDs, normalized status enums, signed webhook payload structures **as data shapes** (not persistence).
- **Crypto & safety primitives:** HMAC helpers, constant-time compare, canonical JSON signing **if** identical across products.
- **Small pure helpers:** string/slug guards, safe truncation, clock interface for tests.
- **Shared exceptions** that mean the same thing everywhere (e.g. `SignatureInvalid`, `EngineNotFound`) **without** HTTP mapping.

## Forbidden in `dply-core` (OUT)

| Category | Reason |
| -------- | ------ |
| Livewire components, views, CSS, JS | Product UX; churns constantly |
| Migrations, factories, Eloquent models | Product-owned schema |
| Route definitions, middleware stacks, policies | App composition |
| “Default” deploy implementation for BYO/Edge/Cloud | Belongs in app or `dply-*-engine` package |
| Billing, plans, entitlements | Product and legal variance |
| OAuth/social login flows | App concern unless a **generic** token contract is agreed (ADR) |
| Logging formatters tied to one APM | Integrate per app |
| Feature flags for **one** product | App config |

**Rule of thumb:** If it imports `Illuminate\Http` or `Livewire` for real work, it is **out** (test doubles in `tests/` of the package are fine).

## Escalation: adding to core

1. **Proposal** — One paragraph: what, which products consume it, why not app-local or a scoped package.
2. **Stability check** — List the **public** classes/functions and expected change frequency.
3. **ADR or amend** — Non-trivial additions **amend this ADR** or add **ADR-00x** (e.g. shared event envelope format).

**Default answer to “can we add X to core?” is no** until the above is satisfied.

## Consequences

### Positive

- Core stays **reviewable** and **versionable**; products can pin different core majors if needed.
- Reduces **accidental coupling** between BYO, Edge, Serverless, etc.
- Makes **ownership** obvious: product teams own apps; platform owns core **only** where explicitly agreed.

### Negative

- Some duplication across apps **until** a second product truly needs the same abstraction (that is acceptable).
- Slightly more packages/repos to name and publish (mitigated by monorepo path repos).

## Related

- Platform plan risk row: *Scope creep on “core”* → this ADR.
- Future **ADR-00x:** deploy engine interface + first implementation (BYO wrapper) may **reference** types defined here but **implement** outside core.

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
