# ADR-003: `projects` table + migration from `sites` (BYO database only)

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted   |
| **Date**     | 2026-03-23 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) §5, §9 Phase C; [ADR-002](./0002-deploy-engine-interface-byo-wrapper.md) |

## Context

BYO today centers on **`sites`**: each row ties to **`server_id`**, domains, git, nginx, SSL, deploy settings, and **`site_deployments`** history. That model is **VM-shaped** and overloaded as a “control plane” record.

The platform plan calls for a **`project`** (or **application**) entity per database as the **unit** for orchestration—deployments keyed to project, product-specific detail in children or JSON—while **`server_id` and SSH-shaped fields stay BYO-only** ([§5](../MULTI_PRODUCT_PLATFORM_PLAN.md#5-data-model-direction)).

We must evolve the **BYO database only** ([ADR-005](./0005-database-per-product-deploy.md)): no shared schema with Serverless/Edge/Cloud in v1.

## Decision

### 1. What `projects` means in BYO

- **`Project`** is the **canonical deployable identity** in the control plane: organization scope, stable id for webhooks/API/deploy history, and future **`DeployEngine::for($project)`** ([ADR-002](./0002-deploy-engine-interface-byo-wrapper.md)).
- **Product line is implicit:** the BYO app only stores BYO projects. Do **not** add a five-value “product enum” spanning all future lines unless one binary serves multiple lines (not planned for v1).
- **BYO-specific infrastructure** (server, document root, PHP version, nginx snippets, `server_id`) remains on a **BYO extension** of the project—not on a hypothetical generic row that must also hold Lambda ARNs.

### 2. Recommended schema shape (conceptual)

| Layer | Responsibility |
| ----- | ---------------- |
| **`projects`** | `id`, `organization_id`, owning `user_id` (if today’s sites have it), **`name`**, **`slug`** (unique per org or global per current rules), timestamps. Optional: **`type`** or **`kind`** string limited to BYO values only (e.g. `byo_site`) until another shape exists in this DB. Optional: **`meta`** JSON for rare forward-compatible keys—avoid dumping the whole site into JSON. |
| **`sites` (interim)** | Continues to hold **VM/runtime** fields and **`project_id`** FK → `projects.id` (**1:1** for current BYO sites). |

**Alternative** (larger cut): rename `sites` → `projects` and introduce **`byo_site_resources`** for server/nginx fields. **Rejected for v1** unless a migration spike proves lower risk—the **add `projects` + FK** path preserves table names familiar to the codebase and allows incremental column moves.

### 3. Migration strategy (expand → backfill → contract)

Execute **only** in the BYO app’s migrations against the **BYO** database.

1. **Create `projects`** with columns agreed in implementation (minimum: org, name, slug, timestamps).
2. **Add `sites.project_id`** (nullable, indexed, FK to `projects`).
3. **Backfill:** for each `sites` row, create one `projects` row (copy name/slug/org/user from `sites` per current model), set `sites.project_id`.
4. **Enforce:** make `sites.project_id` **NOT NULL** after backfill; unique constraint **1:1** (e.g. unique `project_id` on `sites`).
5. **Deployments:** add **`project_id`** to **`site_deployments`** (nullable first), backfill from `site_id`, then NOT NULL + FK to `projects`; keep **`site_id`** temporarily for code paths that still read it, or dual-write in application layer until refactors land.
6. **Follow-up PRs:** move webhook secret, git URL, branch, or other “project-level” fields from `sites` to `projects` **only** when call sites are updated—avoid one giant migration.
7. **Naming:** new routes or UI may say “Project” internally while customer copy can remain “Site” until a product decision changes copy.

### 4. Non-goals (this ADR)

- Changing **customer-visible** product name or URL structure (unless explicitly part of the same release).
- **Dropping** `sites` or **`site_id`** everywhere in one release—removal is a **later** phase after code uses `project_id` end-to-end.
- **Serverless / Edge / Cloud** `projects` tables—their schemas are **owned by those apps’ databases** when those apps exist ([ADR-005](./0005-database-per-product-deploy.md)).

### 5. Integrity and operations

- **Slugs and uniqueness:** preserve current rules (org-scoped or global) when copying to `projects`.
- **Webhooks and API:** paths may continue to use **site** ids externally for compatibility; internally resolve **Site → Project** until public API v2 exposes `project` ids—document in release notes if external ids change.
- **Rollback:** keep migrations **reversible** where possible; data backfill may require a down migration that deletes orphan `projects` created in up—test on a copy of production data.

## Consequences

### Positive

- **`DeployEngine`** and future multi-product code have a **stable** primary key (`project_id`) that is not tied to nginx paths or `server_id`.
- Clear place for **non-VM** deploy shapes later **in other apps’ DBs** without polluting BYO `sites`.

### Negative

- Transitional **dual FKs** (`site_id` + `project_id`) and application complexity until cleanup is complete.

## Related

- [ADR-002: deploy engine](./0002-deploy-engine-interface-byo-wrapper.md) — engine eventually targets **`Project`**; may still accept `Site` until this migration completes.
- [ADR-005: database per product](./0005-database-per-product-deploy.md)
- [ADR-004: engine isolation](./0004-engine-isolation-ssh-leakage.md)

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
| 2026-03-23 | BYO implementation: migration `2026_03_26_100000_create_projects_link_sites_and_deployments`; `Project` model; `Site` creates project in `creating`, finalizes `slug`/`name` in `created`, deletes project in `deleted`; `RunSiteDeploymentJob` writes `project_id` on `site_deployments`. |
