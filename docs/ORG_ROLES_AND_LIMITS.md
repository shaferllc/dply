# Organization roles & plan limits

How membership works in your workspace, what each role can do, and how trial versus Pro affects servers, sites, and API access.

## Organization roles

Roles apply **per organization**. The same person can belong to multiple organizations with different roles in each.

| Role | Typical use |
| --- | --- |
| **Owner** | Created the organization or was transferred ownership. Can delete the organization. Billing and subscription are tied to the organization the owner upgrades. |
| **Admin** | Same operational control as an owner for day‑to‑day work **except** deleting the organization (only an owner can do that). |
| **Member** | Full participant: servers, sites, credentials, and settings that are not restricted to admins only. |
| **Deployer** | Deployment‑focused access: push releases and run deploy‑shaped actions. API tokens created by a deployer are limited to deploy‑related abilities (see below). |

**Who counts as an “admin” in the product:** owners and admins share **admin** privileges for organization settings, invites, and many destructive or org‑wide actions.

## Trial limits (non‑Pro)

If your organization does **not** have an active **Pro** subscription (monthly or yearly), limits apply **across the whole organization**—they are not per user:

- **Servers:** up to the configured free cap (default **3**). Creating another server is blocked until you remove a server or upgrade.
- **Sites:** up to the configured free cap (default **10**), counting every site on every server in that organization.

These defaults can differ on self‑hosted installs if an administrator changes environment configuration.

## Pro

With an active **Pro** subscription:

- **Servers** and **sites** are **unlimited** for that organization (subject to fair use and provider capacity).
- The UI may still show **Trial** or **Pro** labels so you can see plan state at a glance.

Optional **seat** billing (when configured) can cap how many members and pending invitations you may have; if both an environment cap and Stripe seats exist, the **lower** limit wins.

## API tokens and the deployer role

Organization **HTTP API tokens** can include granular abilities (read servers, deploy sites, run commands, and so on).

If your role is **deployer**, tokens you create can only include abilities on the deployer **allowlist** (for example read servers/sites and deploy)—even if the UI would normally offer broader scopes for admins.

Creating new API tokens may require **Pro** when your app instance enables that gate (`DPLY_API_TOKENS_REQUIRE_PAID_PLAN`).

## Profile versus organization settings

- **Profile** (your user): email, password, two‑factor, OAuth accounts, personal SSH keys, **Git source control** connections.
- **Organization**: servers, sites, server provider credentials, billing, members, org‑scoped SSH keys, notification channels, and API tokens.

Git providers live under **profile** so one GitHub (or GitLab, Bitbucket) connection can serve repos across orgs you belong to.

## Related

- [Connect a cloud provider](/docs/connect-provider) — infrastructure API tokens for provisioning.
- [Source control & deploy flow](/docs/source-control) — repos, webhooks, and deployments.
