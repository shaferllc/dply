# Edge delivery

The **Delivery** section covers **where builds are published** and how traffic reaches your app: CDN backend, hybrid SSR origin, image optimization, and cache purge.

Repository and build command settings live under **Build**; this section is about the edge network and origin behavior.

## Edge delivery (read-only)

Shows:

- **Mode** — Dply Edge (managed) or your Cloudflare account (BYO credential)
- **Publish hostname** — default `{slug}.on-dply.site` (or org testing domain) for managed delivery
- **Edge zone** / **Cloudflare account** — when applicable

## Convert to hybrid SSR

Static sites can **Convert to hybrid** to proxy dynamic routes to an origin while static assets stay on Edge. Enter an **Existing origin URL** (dply Cloud app, external HTTPS URL, etc.) and confirm conversion.

Default proxy routes include `/api/*` and `/_next/data/*`; edit them after conversion under **SSR origin (hybrid)**.

## Hybrid origin settings

For hybrid sites:

| Setting | Purpose |
|---------|---------|
| **Origin URL** | HTTPS base URL for SSR (linked Cloud `live_url` or external) |
| **Proxy routes** | Path patterns fetched from origin vs served statically from Edge |
| **Healthcheck path** | Origin URL probed before a deploy goes LIVE |
| **Failover HTML** | Static page when origin returns 5xx or times out |
| **Purge edge cache by tag** | Drop cached entries tagged via `Cache-Tag` or `X-Dply-Cache-Tag` |
| **Origin auth secret** | Rotate shared secret for origin requests |

Hybrid origin saves republish the Worker host map immediately (no full redeploy required for route changes).

## Image optimization

When enabled, Edge can resize and reformat images at the CDN. Configure signing secret rotation for secure transform URLs. See the **Image optimization** operator doc for URL patterns and limits.

## Related sections

- **Build** — static build output and SPA fallback
- **Deploys** — hybrid deploys publish static assets to Edge; SSR hits the origin configured here
- **Traffic & analytics** — CDN request and bandwidth stats
