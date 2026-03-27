## Learned User Preferences

- Use Tailwind CSS v4 with CSS-first configuration (`@import "tailwindcss"`, `@theme`, and related v4 patterns) for new and updated styling.
- Use one shared site header across marketing and authenticated app layouts; only the navigation should differ for guests versus signed-in users.
- Keep the header logo visually prominent (larger than a minimal favicon-scale mark).
- Auth screens should pair the form with supporting layout: short value props, icons, or similar context—not a bare form only.
- Aim for an enterprise-ready SaaS look and feel consistent with the product logo and brand colors.
- Include a **features** (or equivalent) page that explains **how product capabilities connect**, not only isolated feature lists.
- For BYO, surface **subscription and plan limits** in the product UI and keep them aligned with **docs** (roles, quotas, gates).

## Learned Workspace Facts

- Multi-product direction is documented in `docs/MULTI_PRODUCT_PLATFORM_PLAN.md`: one shared monorepo and Composer packages, with a **separate database per product** and no shared transactional schema across lines in v1; **example domains in that doc are provisional** until final DNS/product choices. **Default onboarding and operator documentation prioritize the repo-root BYO app**; other `apps/*` products are optional until priorities change (see that plan and `docs/BYO_LOCAL_SETUP.md`).
- Implementation priority for new product lines after BYO: **Serverless**, then **Cloud**, then **WordPress**, then **Edge**.
- **dply Cloud** is long-running **PHP and Rails** hosting; **dply Edge** is **JavaScript frameworks and static** sites (git, previews, CDN-style delivery), not the default home for Rails monoliths.
- **dply Serverless** lives in **`apps/dply-serverless`** (own database) with **provisioner adapters** for **AWS Lambda** (SDK: describe, zip upload, allow-listed **S3** artifacts and per-project bucket narrowing), **Cloudflare Workers**, **Netlify** (zip to deploy API), and **Vercel** (zip entries to **`/v13/deployments`**), plus roadmap stubs for other providers; the control plane includes **encrypted per-project credentials**, **project settings** overrides, **Bearer + webhook** deploy entrypoints, **read APIs** for projects and deployments, and optional **Bref + SQS** for running the Serverless app itself (see that app’s README and `docs/`).
- Control-plane schema changes such as introducing **`projects`** and migrating from **`sites`** are scoped to the **BYO app database** until other product apps and databases exist (see ADR-003).
- Reduce **engine leakage** (e.g. SSH-shaped code in non-BYO workers) with **separate queues or worker pools per product**, **adapter-only provider code**, and review discipline.
- Keep **`dply-core`** (or the shared package equivalent) to a **small, stable surface**; expand it deliberately and record boundaries in ADRs when needed. In this monorepo, consume **`shaferllc/dply-core`** via **Composer path** to **`packages/dply-core`** unless a deploy target intentionally uses a **published Git** dependency instead.
- BYO exposes **`/settings`** as a **hub with a shared settings layout** (profile, two-factor, organizations, billing, credentials, docs links). **Per-organization deploy-finish email** can be disabled without affecting **outbound integration webhooks** (separate controls).
- **Organization** subscription and plan limits apply to **all servers and all sites** in that org (one envelope, not per-site SKUs unless deliberately introduced). **Profile, 2FA, and OAuth-linked accounts** stay **user-scoped** across orgs and sites.
- Deploying a monorepo app behind nginx: set **`root`** to that app’s **`public/`** (e.g. `apps/dply-cloud/public` in a checkout, or the released app tree’s `public`). **Independent deploys do not require separate Git repositories** if each product has its own env, database, and build or deploy target (see `docs/MONOREPO_AND_APPS.md`).
