# Organization roles, API tokens, trial limits, and Pro billing (BYO)

This applies to the **bring-your-own-server (BYO)** app at the repository root. Billing and usage limits are **per organization**. Profile, 2FA, and OAuth are **per user** (see `docs/MULTI_PRODUCT_PLATFORM_PLAN.md` §2 in the repo).

## Roles (organization membership)

| Role | Typical use |
|------|-------------|
| **Owner** | Full control; only the owner can delete the organization (see `OrganizationPolicy`). |
| **Admin** | Billing, invites, API tokens, integration webhooks, delete **servers** and **sites**, manage teams. Same “admin” powers as owner for day-to-day infra except org delete. |
| **Member** | Use servers and sites: create **servers** and **sites** within plan limits, run deploys, edit site settings, use credentials (not deployer). **Cannot** delete sites (only owner/admin). **Cannot** access billing or org-level admin features. |
| **Deployer** | CI-style access: trigger deploys and use the API within token scope. **Cannot** create **servers** or **sites**, **cannot** open provider credentials, and **cannot** manage billing. API tokens with deploy scope also block `commands.run` even if the token lists `*`. |

Invites are sent from **Organization → Members** (admins only).

## Trial limits and Pro billing (servers & sites)

Limits apply to the **entire organization** (every server and every site in that org). They are **not** billed or counted per site.

| Stage | Servers | Sites |
|------|---------|--------|
| **Trial / non-Pro** (no active Pro subscription) | Up to `SUBSCRIPTION_SERVERS_FREE_LIMIT` (default **3**) | Up to `SUBSCRIPTION_SITES_FREE_LIMIT` (default **10**) |
| **Pro** (Stripe price IDs match `pro_monthly` / `pro_yearly` in `config/subscription.php`) | **Unlimited** | **Unlimited** |

Configure defaults in `.env`:

- `SUBSCRIPTION_SERVERS_FREE_LIMIT`
- `SUBSCRIPTION_SITES_FREE_LIMIT`
- `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_PRO_YEARLY`

Public pricing is organization-based. Any member cap still present in runtime config should be treated as an internal safeguard, not customer-facing seat pricing.

## Where this appears in the app

- **Organization** page: **Plan & usage** (all members).
- **Billing** (admins): same numbers plus subscribe / Stripe portal.
- **Servers / sites UI:** “Cannot add site” or server create blocked when at limit or role disallows it.

## API (summary)

- Tokens are created by **org admins** only.
- Deployer role and deploy-scoped tokens cannot use `POST /api/v1/servers/{id}/run-command` (`commands.run`).
- Plan limits do not add separate API quotas beyond the global rate limit; they affect **whether** new servers/sites can be created in the UI (and the same policies apply if you add create endpoints later).

Full API reference: `docs/API.md` in the repository.

## Related docs (repository)

- `docs/DEPLOYMENT_FLOW.md` — deploy pipeline and webhooks
- `docs/MULTI_PRODUCT_PLATFORM_PLAN.md` — multi-product roadmap
