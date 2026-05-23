# dply Edge roadmap

Status: in progress (2026-05-23)

First-party Netlify-style platform: git-connected builds, branch previews, CDN delivery via Cloudflare R2 + Workers.

## Phases

### Phase 0 — Foundation ✅

- ADR + feature flag `surface.edge`
- Edge index/create UI scaffold
- Data model: `edge_deployments`, site helpers

### Phase 1 — Core deploy loop ✅

- Docker build runner + runtime detection
- R2 artifact upload + KV host map publish
- CF Worker package (`packages/edge-worker`)
- Fake edge mode for local/CI

### Phase 2 — Product UX ✅ (2026-05-23)

- Site workspace edge dashboard (deploy history, redeploy, rollback)
- GitHub webhooks: inbound controller + **auto-provision** from Build settings (enable/disable, account picker, last event)
- Build logs captured to `{storage_prefix}/build.log` and shown in Logs panel
- Overview hero auto-deploy status; previews show PR number

### Phase 3 — Custom domains ✅ (2026-05-23)

- `EdgeCustomDomainProvisioner` — pending → verify → ready DNS flow
- CNAME target + copy, Verify DNS button, status badges in Domains UI
- `VerifyEdgeCustomDomainsJob` scheduled every 15 minutes
- Pending custom domains gated in fake-edge middleware (503 pending page)
- TLS copy qualified (Cloudflare proxy / managed zone); Custom Hostnames API stub in `EdgeCloudflareClient` for Phase 3b

### Phase 4 — SSR (split)

#### Phase 4a — Hybrid origin-fetch ✅ (2026-05-23)

- `runtime_mode = 'hybrid'` + `meta.edge.origin` (url, routes)
- Worker proxies to origin on R2 miss for matching routes
- Create flow: hybrid mode + SSR origin URL
- Build settings shows hybrid origin (read-only in v1)

#### Phase 4b — Worker-native SSR (deferred)

- OpenNext / `@cloudflare/next-on-pages` build pipeline in `EdgeBuildRunner`
- Per-deployment Worker script upload via `edge:worker:deploy`
- `runtime_mode = 'ssr'` — blocked until 4b ships
- See [edge-product-boundary ADR](adr/edge-product-boundary.md)

## Infra prerequisites

See **[edge-production-setup.md](edge-production-setup.md)** for the full runbook.

- Cloudflare: R2 bucket, Workers KV namespace, Worker deployed (`php artisan dply:edge:bootstrap`, `php artisan edge:worker:deploy`)
- Wildcard DNS on testing domains (`*.dply.host`) → Worker
- Queue workers with Docker for builds
- Env: `DPLY_EDGE_*` (see `config/edge.php`); validate with `php artisan dply:edge:doctor --probe`

## Related

- [edge-product-boundary ADR](adr/edge-product-boundary.md)
- Cloud preview pattern: `app/Actions/Cloud/CreateCloudPreviewSite.php`
