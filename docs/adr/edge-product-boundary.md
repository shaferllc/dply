# ADR: dply Edge product boundary

Status: accepted (2026-05-22)

## Context

dply ships multiple product lines from one Laravel app. **Cloud** hosts long-running PHP/Rails containers on managed backends. **BYO** serves sites from customer VMs. **Serverless** proxies HTTP to function URLs. Operators also need a **Netlify-shaped** path: git → build → static/SSG artifacts → global CDN delivery with previews.

Historical note: the token `dply_edge` was absorbed into Cloud (`dply_cloud`). Edge delivery must use a **new** host kind to avoid collision.

## Decision

1. **dply Edge** = first-party static/SSG delivery on dply-controlled Cloudflare R2 + Workers. Not customer Netlify accounts.
2. **Host kind** = `dply_edge_delivery` (`Server::HOST_KIND_DPLY_EDGE`). Never reuse `dply_edge`.
3. **Site discriminator** = `sites.edge_backend = 'dply_edge'` plus `meta.edge.runtime_profile = 'edge_web'`.
4. **v1 scope** = static + SSG export only (`meta.edge.runtime_mode = 'static'`). SSR reserved for v2 (`runtime_mode = 'ssr'`).
5. **Build plane** = dply queue workers run isolated Docker builds; artifacts land in immutable R2 prefixes per `edge_deployments` row.
6. **Delivery plane** = Cloudflare Worker reads KV host map, serves from R2. Laravel orchestrates; it does not serve production static assets.
7. **Previews** = sibling Site rows (same pattern as Cloud previews), idempotent on `(parent, branch)`.

## Boundaries

| Product | Workload | Delivery |
|---------|----------|----------|
| Edge | JS frameworks, static, SSG | R2 + CF Worker |
| Cloud | PHP, Rails, containers | DO App Platform / App Runner |
| BYO Static | Any static on customer VM | nginx/Caddy on VM |
| Serverless | Functions | dply proxy → function URL |

## Consequences

- New tables/columns: `edge_deployments`, `sites.edge_backend`, `sites.edge_backend_id`.
- Edge jobs stay separate from `DeployEngineResolver` until SSR v2.
- Platform CF credentials (`DPLY_EDGE_*`) are dply-operated; customer Cloudflare tokens in `/credentials` remain DNS-only.
