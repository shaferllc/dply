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

## Pricing (summary)

- **Flat per-site fee** while the Edge app is live (see create sidebar and Billing & usage).
- **Branch/PR previews** are free.
- Optional **usage pass-through** (CDN requests and bandwidth) may apply when enabled for your organization.

See **Edge billing & usage** in your site workspace for month-to-date numbers.

## Edge workspace sections

After create, open your site from **Infrastructure → Edge** or the fleet index. The sidebar is grouped by job — **Deploy**, **Networking**, **Observability**, etc. — so you are not scrolling one giant “build settings” page.

### Why it is split

Early Edge workspaces put repo config, build command, webhooks, env vars, CDN delivery, hybrid origin, previews, and image optimization on a **single Build settings page**. That page was hard to scan and mixed “change how we compile” with “change who can see previews.”

The workspace now splits that into **focused sections**. Each section has its own doc guide in **Documentation → Edge guides**.

### Section map

**General**

| Section | What you do here |
|---------|------------------|
| **Overview** | Live URL, source repo, delivery summary, quick deploy |
| **Members** | Who can access this site (when enabled) |
| **Audit log** | Site activity history (when enabled) |

**Deploy**

| Section | What you do here |
|---------|------------------|
| **Deploys** | History, redeploy, rollback |
| **Build** | Build command, output dir, repo `dply.yaml` snapshot, retention |
| **Environment** | Production env vars (secrets) |
| **Deploy triggers** | Deploy hooks + GitHub auto-deploy webhooks |
| **Previews** | PR/branch preview list, **preview protection**, comment widget *(parent sites only)* |

**Networking**

| Section | What you do here |
|---------|------------------|
| **Domains** | Custom hostnames and DNS verification |
| **Routing** | Read-only redirects / rewrites / headers from `dply.yaml` |
| **Bindings** | Attach Cloudflare KV, R2, D1 for `dply.yaml` bindings |
| **Delivery** | CDN backend (read-only), hybrid SSR origin, image optimization, cache purge |

**Observability**

| Section | What you do here |
|---------|------------------|
| **Traffic & analytics** | CDN stats and vitals (managed delivery) |
| **Billing & usage** | Edge fees and usage for this site |
| **Build & deploy logs** | CI output per deployment |

**Danger zone**

| Section | What you do here |
|---------|------------------|
| **Danger zone** | Delete the site |

Preview **child** sites show a reduced sidebar (Overview, Deploys, Domains, Build, Logs, Danger) — no fleet billing, previews list, or preview protection on the child.

### Quick “where do I…?”

| I want to… | Go to |
|------------|--------|
| Change build command or output folder | **Build** |
| Add `DATABASE_URL` for the build | **Environment** |
| Hook Sanity publish to a deploy | **Deploy triggers** |
| Password-protect preview URLs | **Previews → Preview protection** |
| Point SSR routes at Cloud | **Delivery** |
| Edit redirects in `dply.yaml` | Git + redeploy (view on **Routing**) |
| Enable deploy on push to `main` | **Build** (toggle) + **Deploy triggers** (GitHub webhook) |

## When to use Edge vs Cloud

| Choose **Edge** | Choose **Cloud** |
|-----------------|------------------|
| Static/SSG output | Long-running PHP, Rails, Node server |
| Global CDN-first delivery | Needs database on same host |
| PR preview URLs for front-end | Worker/cron on container |

Hybrid Edge combines both: CDN for assets, Cloud for SSR.
