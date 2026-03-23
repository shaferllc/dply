# ADR-002: Deploy engine interface + first implementation (BYO wrapper)

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted   |
| **Date**     | 2026-03-23 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) §4, §9 Phase B; [DEPLOYMENT_FLOW.md](../DEPLOYMENT_FLOW.md) |

## Context

Today, BYO site deploys are driven by **`RunSiteDeploymentJob`**, **`SiteGitDeployer`**, pipeline/hooks, and SSH-backed execution—see [DEPLOYMENT_FLOW.md](../DEPLOYMENT_FLOW.md). Entry points include the **site deploy webhook**, **API**, and **UI** (manual deploy).

We need a **stable seam** so future products (**Serverless**, **Edge**, …) can implement their own **deploy engines** without shotgun edits across controllers and jobs. **Phase B** of the platform plan requires:

- A **`DeployEngine`** (or equivalent) **interface** and **factory**.
- **`ByoServerDeployEngine`** as a **wrapper** around the **existing** BYO behavior—**no behavior change** for customers in the first iteration.

## Decision

### 1. Conceptual model

- A **deploy engine** is responsible for: given a **deployable unit** (today: BYO **`Project`** as the control-plane unit, with **`Site`** for SSH/git; later: other product-specific models), a **trigger** (webhook, API, manual), and optional **metadata** (idempotency key, acting user), **execute** the deployment lifecycle and **persist** status/logs on the product’s models.
- **Orchestration** (locks, `SiteDeployment` rows, notifications, audit, idempotency cache) may remain in a **thin coordinator** (e.g. the existing job or a renamed service) that **delegates** the heavy “how to run” work to the engine.
- **BYO** engines always run work that ultimately uses **SSH** against a customer server; that implementation **must not** be referenced from non-BYO apps ([ADR-004](./0004-engine-isolation-ssh-leakage.md)).

### 2. Interface placement (per [ADR-001](./0001-dply-core-boundaries.md))

| Artifact | Location |
| -------- | -------- |
| **`DeployEngine` interface** | **`dply-core`** *only when* a second product app needs to type-hint it; **until then**, define in BYO app namespace e.g. `App\Contracts\DeployEngine` to avoid premature core extraction. |
| **Shared DTOs** (trigger enum, read-only deploy request snapshot) | Same rule: **BYO app first**; promote to `dply-core` when Serverless/Edge consumes the same shape. |
| **`ByoServerDeployEngine`** | **BYO app only** (or `packages/dply-byo-deploy` later)—implements the interface by **calling existing** `SiteGitDeployer` / pipeline / SSH services. |

**Default for first PR:** keep the interface in **`App\Contracts`**; open a follow-up to move to `dply-core` when the Serverless app exists and Composer depends on core.

### 3. Factory / resolution

- Introduce a **`DeployEngineResolver`** (or static factory) that returns the correct engine for the deployable.
- **Phase B scope:** only **`ByoServerDeployEngine`** is registered; **`forProject(Project)`** is primary; **`forSite(Site)`** loads the site’s project and delegates (see [ADR-003](./0003-projects-table-migration-from-sites.md)).
- **Later:** resolver branches on **product** or **`Project` type** when multiple engines exist in the same app during migrations—or each product app registers **one** engine only ([ADR-004](./0004-engine-isolation-ssh-leakage.md)).

### 4. First implementation: BYO wrapper

**`ByoServerDeployEngine`** must:

1. **Delegate** to current services (`SiteGitDeployer`, pipeline runner, etc.) so **observable deploy behavior** matches today’s production (atomic/simple paths, hooks, logging).
2. **Not** duplicate lock/idempotency/notification logic in v1 unless moving it from the job is cleaner—**prefer** moving **minimal** code: job acquires lock, creates `SiteDeployment`, calls `$engine->run($context)`, handles completion/failure as today.
3. **Accept** a **context object** (e.g. `ByoDeployContext`) carrying: **`Project`** (control plane), trigger string, optional idempotency hash, optional `auditUserId`, and references needed for logging; the engine resolves the linked **`Site`** for SSH/git.

### 5. Entry-point migration (incremental)

Migrate **one** path first (recommended: **`RunSiteDeploymentJob::handle`** only), then webhook/API controllers if they bypass the job.

**Exit criteria (Phase B):**

- All deploy entry points for BYO sites go through **`DeployEngineResolver` → `ByoServerDeployEngine`** (or the job is the single entry and already uses the engine).
- Adding a **second** engine implementation does not require editing unrelated HTTP controllers—only registration/resolver wiring.

## Non-goals (this ADR)

- Introducing the **`projects`** table or renaming `Site` globally (**ADR-003**, Phase C).
- Implementing **Serverless** or **Edge** engines.
- Changing **webhook signing** or **API** contracts ([DEPLOYMENT_FLOW.md](../DEPLOYMENT_FLOW.md)).

## Consequences

### Positive

- Clear extension point for multi-product platform without a big-bang rewrite ([ADR-006](./0006-phased-delivery-byo-shipping-flags.md)).
- Tests can target **`ByoServerDeployEngine`** + fake context while integration tests keep covering full job flow.

### Negative

- One layer of indirection; team must avoid pushing **too much** into the interface before the second product needs it.

## Related

- [ADR-001: dply-core boundaries](./0001-dply-core-boundaries.md)
- [ADR-004: engine isolation](./0004-engine-isolation-ssh-leakage.md)
- [ADR-006: phased delivery](./0006-phased-delivery-byo-shipping-flags.md)

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
| 2026-03-23 | **Amendment:** resolver/context keyed by **`Project`**; **`ByoDeployContext`** carries **`Project`**; **`ByoServerDeployEngine`** loads **`site`** for BYO SSH. |
