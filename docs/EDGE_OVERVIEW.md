# Edge overview

dply **Edge** hosts JavaScript frameworks, static sites, and static-site generators (SSG) from Git. Each deploy builds your project, publishes assets to global edge delivery, and serves them over HTTPS.

## What Edge is for

Use Edge when your app ships as **static HTML, CSS, and JavaScript** (or SSG output such as Next.js static export, Astro, Nuxt generate, Vite, etc.).

Edge is **not** the default home for long-running servers, databases, or Rails/PHP monoliths. Those belong on **dply Cloud** or BYO servers.

## Static vs hybrid

| Mode | Best for | How it works |
|------|----------|--------------|
| **Static / SSG** | SPAs, marketing sites, docs sites | dply builds and serves all files from the edge CDN. |
| **Hybrid** | SSR frameworks (Next.js App Router, Nuxt SSR, etc.) | Static assets from Edge; dynamic HTML fetched from a **Cloud origin** you link or auto-provision. |

When dply detects an SSR framework during create, **Hybrid** is suggested automatically. You can deploy a linked Cloud app as the origin or enter an external origin URL.

## Managed vs your Cloudflare

| Delivery | Who runs Cloudflare | Typical hostname |
|----------|---------------------|------------------|
| **Dply Edge (managed)** | dply platform | `{slug}.on-dply.site` (or your org testing domain) |
| **Your Cloudflare account** | You (BYO credential) | Hostnames on zones in your Cloudflare account |

Delivery backend is **fixed after the first publish** in v1. Create a new Edge site to switch between managed and BYO Cloudflare.

## Pricing (summary)

- **Flat per-site fee** while the Edge app is live (see create sidebar and Billing & usage).
- **Branch/PR previews** are free.
- Optional **usage pass-through** (CDN requests and bandwidth) may apply when enabled for your organization.

See **Edge billing & usage** in your site workspace for month-to-date numbers.

## Edge workspace sections

After create, open your site from **Infrastructure → Edge** or the fleet index:

- **Overview** — delivery summary, source repo, quick links
- **Deploys** — history, redeploy, rollback
- **Domains** — default hostname and custom domains
- **Build settings** — build command, webhooks, hybrid origin
- **Previews** — PR/branch preview deployments
- **Traffic & analytics** — CDN stats and vitals (managed delivery)
- **Billing & usage** — Edge-specific fees and usage
- **Build & deploy logs** — CI output per deployment
- **Danger zone** — delete the site

## When to use Edge vs Cloud

| Choose **Edge** | Choose **Cloud** |
|-----------------|------------------|
| Static/SSG output | Long-running PHP, Rails, Node server |
| Global CDN-first delivery | Needs database on same host |
| PR preview URLs for front-end | Worker/cron on container |

Hybrid Edge combines both: CDN for assets, Cloud for SSR.
