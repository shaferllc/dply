## Learned User Preferences

- Use Tailwind CSS v4 with CSS-first configuration (`@import "tailwindcss"`, `@theme`, and related v4 patterns) for new and updated styling.
- Use one shared site header across marketing and authenticated app layouts; only the navigation should differ for guests versus signed-in users.
- Keep the header logo visually prominent (larger than a minimal favicon-scale mark).
- Auth screens should pair the form with supporting layout: short value props, icons, or similar context—not a bare form only.
- Aim for an enterprise-ready SaaS look and feel consistent with the product logo and brand colors.

## Learned Workspace Facts

- Multi-product direction is documented in `docs/MULTI_PRODUCT_PLATFORM_PLAN.md`: one shared monorepo and Composer packages, with a **separate database per product** and no shared transactional schema across lines in v1.
- Implementation priority for new product lines after BYO: **Serverless**, then **Cloud**, then **WordPress**, then **Edge**.
- **dply Cloud** is long-running **PHP and Rails** hosting; **dply Edge** is **JavaScript frameworks and static** sites (git, previews, CDN-style delivery), not the default home for Rails monoliths.
- **dply Serverless** adds **multiple infrastructure providers** (e.g. AWS, DigitalOcean, others) as adapters within one product, not as separate businesses per cloud.
- Control-plane schema changes such as introducing **`projects`** and migrating from **`sites`** are scoped to the **BYO app database** until other product apps and databases exist (see ADR-003).
- Reduce **engine leakage** (e.g. SSH-shaped code in non-BYO workers) with **separate queues or worker pools per product**, **adapter-only provider code**, and review discipline.
- Keep **`dply-core`** (or the shared package equivalent) to a **small, stable surface**; expand it deliberately and record boundaries in ADRs when needed.
