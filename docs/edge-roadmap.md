# dply Edge roadmap

Status: in progress (2026-05-22)

First-party Netlify-style platform: git-connected builds, branch previews, CDN delivery via Cloudflare R2 + Workers.

## Phases

### Phase 0 — Foundation (current)

- ADR + feature flag `surface.edge`
- Edge index/create UI scaffold
- Data model: `edge_deployments`, site helpers

### Phase 1 — Core deploy loop

- Docker build runner + runtime detection
- R2 artifact upload + KV host map publish
- CF Worker package (`packages/edge-worker`)
- Fake edge mode for local/CI

### Phase 2 — Product UX

- Site workspace edge dashboard (deploy history, redeploy, rollback)
- GitHub webhooks (push + PR previews)

### Phase 3 — Custom domains

- Attach hostname → KV entry
- DNS validation via platform Cloudflare API

### Phase 4 — SSR (v2)

- `runtime_mode = 'ssr'` for Next/Nuxt server rendering
- Worker optional origin fetch

## Infra prerequisites

- Cloudflare: R2 bucket, Workers KV namespace, Worker deployed
- Wildcard DNS on testing domains (`*.dply.host`) → Worker
- Queue workers with Docker for builds
- Env: `DPLY_EDGE_*` (see `config/edge.php`)

## Related

- [edge-product-boundary ADR](adr/edge-product-boundary.md)
- Cloud preview pattern: `app/Actions/Cloud/CreateCloudPreviewSite.php`
