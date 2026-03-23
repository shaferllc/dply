# ADR-005: Enforce separate `DB_*` per product deploy

| Field        | Value      |
| ------------ | ---------- |
| **Status**   | Accepted   |
| **Date**     | 2026-03-23 |
| **Deciders** | Platform   |
| **Context**  | [MULTI_PRODUCT_PLATFORM_PLAN.md](../MULTI_PRODUCT_PLATFORM_PLAN.md) §8, §10 |

## Context

Each product app (**BYO**, Serverless, Cloud, …) must use its **own** database. A mis-copied `.env`, wrong secret in Kubernetes, or reused Terraform module can point **Edge** or **Serverless** workers at the **BYO** database—causing data corruption, auth bleed, and impossible migrations.

We need **enforceable** separation: not only convention, but **verification** in CI and **operations**.

## Decision

Use **three layers** together:

1. **Convention + config** — One explicit **product identifier** per deployable; **database names** follow a **mandatory prefix** (see inventory in the platform plan §8).
2. **CI / automation** — Before or after `composer install`, assert that the env (or env **template**) for **this** app matches the expected `DB_DATABASE` pattern for that product.
3. **Runbook** — Human verification on every new environment and after infra changes; optional **production boot assertion** (fail fast) where low risk.

Details live in the **[database isolation runbook](../runbooks/database-isolation.md)**.

## Rules

| Rule | Detail |
| ---- | ------ |
| **One DB name per product** | No two apps share the same `DB_DATABASE` value in any environment (dev/staging/prod). |
| **Named prefixes** | Prefer `dply_byo`, `dply_serverless`, `dply_cloud`, `dply_wordpress`, `dply_edge` (adjust if your org uses another prefix; document in runbook). |
| **Secrets isolation** | In Vault / K8s secrets / Forge: **one secret object per app per env**; do not reference another product’s `DATABASE_URL`. |
| **Read replicas** | Extra connections (`mysql_read`, …) must still be **same product** data; never point a replica URL at another product’s primary. |

## Optional: production boot assertion

Each app may register (in `AppServiceProvider` or a dedicated provider, **production only**):

- Read `config('dply.product')` (or `APP_PRODUCT`) — must be set per deployable.
- Assert `DB_DATABASE` matches the **allowlist** for that product (prefix or exact set). On mismatch: **log + abort** (503) so a bad deploy never serves traffic against the wrong schema.

This is **not** a substitute for CI; it is a **last line of defense**. Do not expose database names in HTTP responses.

## Consequences

### Positive

- Wrong wiring fails **in CI** or **at boot**, not after user data mixes.

### Negative

- Slightly more env vars (`APP_PRODUCT`) and CI maintenance when adding a new product line.

## Related

- [ADR-004: engine isolation](./0004-engine-isolation-ssh-leakage.md) — complementary (wrong queue vs wrong DB).
- [Runbook: database isolation](../runbooks/database-isolation.md).

## Amendment log

| Date       | Change |
| ---------- | ------ |
| 2026-03-23 | Initial acceptance |
