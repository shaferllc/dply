# Organization shared secrets (v1)

Status: **locked for the first slice.** Analog: [Laravel Cloud Secrets](https://laravel.com/cloud/docs/secrets).

This is **not** `docs/SECRETS_UI.md` (site `.env` Time Machine) and **not** residency (age key + Vault/Doppler). Those stay. This page is a shared, write-never vault of named values you **link onto sites**.

---

## Problem

Operators type the same `STRIPE_SECRET` / `MAIL_PASSWORD` into every site `.env`. Project workspace variables exist, but they inherit to a whole project, cannot collide by key, and are the wrong shape for “one org vault, attach per site.”

## What ships

An org-scoped secret: **ULID identity**, a **key** (may collide), an **encrypted value**, and **notes**. Link it to any **site** (BYO, Cloud, Edge, Serverless). Project is a picker filter only — no inheritance.

| Topic | v1 |
|--------|----|
| Attach target | Sites. No new “environment” object. |
| Key uniqueness | Keys may collide. Identity is the ULID. **Notes required** when the key already exists in the org. **One site cannot link two secrets with the same key.** |
| Read after save | **Write-never.** Rotate = replace value. No reveal, no copy. |
| Merge order | workspace vars → **linked secrets** → bindings → site `.env` |
| Binding-owned key | Warn on link. Site key vs secret: **Override** badge. |
| When it hits the app | **Deploy only** on BYO and Edge. Standalone env push writes typed vars + bindings and **must not** inject or rotate vault secrets. Cloud has no on-disk `.env` — every backend spec write includes linked secrets so an env edit cannot strip them. |
| UI | Org **Secrets** page: **Secrets** (this) \| **Residency** (today’s key + stores). Site Environment: **Link secrets**. |
| Permissions | **Admins** create / rotate / delete. **Site updaters** link / unlink. Delete unlinks every site; those sites need a deploy to drop the key. |
| Crypto | Laravel `encrypted` (`APP_KEY`), same as mail/Redis credentials. Decrypt **only in the deploy (or Cloud spec) job**. Copy: encrypted at rest, **not** “dply cannot read this.” |
| Workspace `is_secret` | **Freeze new ones.** Existing flags keep working. No auto-migrate. |

**Out of v1:** env Time Machine UI, zero-knowledge crypto, killing env push globally, API/CLI/MCP, a 30-secret cap.

---

## Trust copy

Values are encrypted at rest with the application key. dply decrypts them at deploy time to inject them. Do not claim the control plane cannot read vault secrets.

---

## First slice

- `organization_secrets` + `organization_secret_sites`
- Org Secrets tab + site (and Edge) link UI
- `DeploymentSecretInventory` merge + BYO deploy inject via `SiteEnvPusher` (`includeSharedSecrets`)
- Edge production env merge; Cloud `siteEnvVars` merge
- Write-never in Livewire (never echo value after save)
