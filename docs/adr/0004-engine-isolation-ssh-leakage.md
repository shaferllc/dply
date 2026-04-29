# ADR-004: Engine isolation — prevent SSH / BYO leakage into Edge (and other products)

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted (scope updated 2026-04-28) |
| **Date**     | 2026-03-23 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) §4, §10 |

> **Scope update (2026-04-28):** the multi-app `apps/dply-{cloud,wordpress,edge}` direction this ADR referenced was retired. The engine-isolation discipline still applies, but the boundary is now between **engines / queues / worker pools inside the single root app**, not between separate Laravel installs. Forbidden-import lists target package or namespace boundaries within `app/Services/Deploy/` rather than per-app `composer.json` files.

## Context

**BYO** deploys and operations are **SSH-shaped** (`SshConnection`, remote shell, server-bound jobs). **Edge** is **build → artifact → CDN / edge runtime** — no customer shell, no long-lived VM assumption.

Without guardrails, shared packages and copy-paste reuse cause **engine leakage**: Edge (or Serverless, WordPress) accidentally imports BYO services, dispatches BYO jobs, or runs SSH code paths. That breaks the product promise, widens the security surface, and couples release trains.

This ADR turns the plan’s mitigation (**code review + queue separation + adapter-only provider code**) into **enforceable rules**.

## Decision

1. **Each product app owns its engine implementation** — BYO ships `ByoServerDeployEngine` and SSH helpers **inside the BYO app or a `dply-byo-*` package** that **only** the BYO app depends on. Edge never lists that package in `composer.json`.

2. **Provider and cloud interactions are adapter-only at the boundary** — product code depends on **interfaces** (from `dply-core` per [ADR-001](./0001-dply-core-boundaries.md) only when truly shared). **Concrete** adapters live in the app (or product-scoped package): `DigitalOceanByoAdapter` may use SSH + API; `DigitalOceanEdgeAdapter` uses **HTTP APIs / SDKs only** — never `SshConnection`.

3. **Queues and workers are separated per deployable** — each Laravel app uses its own **queue connection names** and **worker processes**. No default “shared” queue where BYO and Edge consume the same jobs.

4. **Verification is layered** — human **code review** plus **automated** checks (forbidden imports, CI) so leakage is caught before merge.

## Rules

### A. Code review (human)

PR checklist for any change that touches deploy, provision, or cloud integration:

- [ ] **Product label:** Is this PR scoped to **one** product app? If it touches two, justify split or extract **interface-only** to core (ADR-001).
- [ ] **No SSH in Edge/Serverless paths:** Confirm no new references to `SshConnection`, `ssh`, `Net_SSH2`, or BYO-only namespaces from non-BYO apps.
- [ ] **Job class ownership:** New `ShouldQueue` jobs live in the **same app** as the engine that dispatches them unless an ADR defines a cross-app contract.

### B. Queue separation (runtime)

| Rule | Detail |
| ---- | ------ |
| **Dedicated connections** | Each app configures `QUEUE_CONNECTION` (or named connections `redis-by`, `redis-edge`, …) so workers for product A **never** read product B’s queues. |
| **Explicit queue names** | Prefix queue names with product slug: `byo-deploy`, `edge-build`, `serverless-publish`. No generic `deploy` shared across apps. |
| **No cross-dispatch by default** | Dispatching a job from Edge that targets BYO’s queue is **forbidden** unless documented integration (rare); prefer **HTTP callbacks** or **outbox + consumer** owned by one side. |
| **Worker deployables** | Run **separate worker containers/processes** per product in production; do not run one mega-worker with all job classes loaded. |

### C. Adapter-only provider code

| Layer | Responsibility |
| ----- | -------------- |
| **Interface** (core or app) | `ProvisionServer`, `PublishArtifact`, `DnsRecordSync` — **no** transport. |
| **BYO adapter** | May compose **SSH + provider API** (e.g. create droplet then bootstrap via shell). |
| **Edge adapter** | **Only** provider HTTP APIs, object storage, CDN APIs, build APIs — **no SSH**. |
| **Serverless adapter** | Provider control plane for functions; **no** SSH unless a future ADR allows a narrow BYO-hybrid (default: no). |

**Lint / convention:** In `apps/dply-edge` (and similar), treat imports of `App\Services\SshConnection` (or equivalent) as **build failures** (see below).

### D. Automated enforcement (CI / static)

Add as engineering backlog; not all required on day one:

1. **Forbidden import test** — PHPUnit (or Rector/PHPStan custom rule) that scans `apps/dply-edge/src` (or `app/` for Edge) and **fails** if forbidden symbols or namespaces appear (e.g. `SshConnection`, `dply-byo`).
2. **PHPStan baseline per app** — stricter rules for Edge paths; optional `composer.json` **conflict** or **replace** to block installing BYO-only package from Edge root.
3. **CODEOWNERS** — platform team owns `packages/dply-core` and any `DeployEngine` contract; product teams own `apps/dply-*`.

## Consequences

### Positive

- Edge and Serverless stay **honest**: no hidden SSH requirement for customers.
- Security and compliance reviews can **scope** infrastructure (SSH keys only exist in BYO fleet).
- Clear place for **duplicate-looking** provider code (two adapters) instead of one dangerous abstraction.

### Negative

- **Some duplication** between BYO and Edge adapters for the “same” cloud brand — acceptable; share **DTOs** or **small pure helpers** in core only per ADR-001, not SSH sessions.

## Relation to other ADRs

- **ADR-001:** Core stays small; **engines are not** “default” in core — **interfaces** might be; **SSH** never.
- **ADR-002:** Deploy engine **interface** + BYO **`ByoServerDeployEngine`** lives in BYO app; Edge (later) registers **only** `EdgeDeployEngine`.

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
