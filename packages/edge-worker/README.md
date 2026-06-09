# dply Edge Worker

Cloudflare Worker that serves dply Edge static/SSG artifacts from R2 using a KV hostname map.

## Bindings

| Binding | Type | Purpose |
|---------|------|---------|
| `ARTIFACTS` | R2 bucket | Immutable deployment artifacts |
| `HOST_MAP` | KV namespace | Hostname → deployment routing metadata |

## KV host map entry

Each hostname key stores JSON:

```json
{
  "storage_prefix": "edge/site-123/deployments/abc/",
  "deployment_id": "abc",
  "spa_fallback": true,
  "headers": {
    "X-Dply-Site": "123"
  }
}
```

- `storage_prefix` — R2 key prefix for the deployment (include trailing slash).
- `deployment_id` — active deployment id (returned as `X-Dply-Deployment-Id`).
- `spa_fallback` — when `true`, unknown paths serve `index.html` after a 404.
- `headers` — optional extra response headers merged onto every response.

## Request flow

1. Read request hostname and look up KV entry.
2. Normalize URL path (`/` → `index.html`; reject `..` segments).
3. Fetch `storage_prefix + path` from R2.
4. On 404 with `spa_fallback`, serve `index.html`.
5. Apply cache policy:
   - `index.html` → `public, max-age=0, must-revalidate`
   - hashed assets (e.g. `app.a1b2c3d4.js`) → `public, max-age=31536000, immutable`
   - other assets → `public, max-age=3600`
6. Apply baseline security headers plus any per-host headers from KV.

## Setup

```bash
cd packages/edge-worker
npm install
```

Copy and edit bindings in `wrangler.toml`:

- `[[r2_buckets]].bucket_name` — your R2 bucket name
- `[[kv_namespaces]].id` — your Workers KV namespace id

Optional: create `.dev.vars` for local-only Wrangler vars (not required for v1).

## Develop locally

```bash
npm run dev
```

Wrangler serves the worker with Miniflare. Seed KV/R2 in the Cloudflare dashboard or via `wrangler kv:key put` / `wrangler r2 object put` before testing hostnames.

Example KV seed:

```bash
wrangler kv:key put --binding=HOST_MAP preview.example.test \
  '{"storage_prefix":"edge/site-1/deploy-9/","deployment_id":"deploy-9","spa_fallback":true}'
```

## Deploy

```bash
npm run deploy
```

After deploy, point preview/production hostnames (for example `*.dply.host`) to the worker route in Cloudflare.

Laravel publishes host map entries using platform credentials from `config/edge.php` (`DPLY_EDGE_*`).

Production setup: [docs/edge-production-setup.md](../../docs/edge-production-setup.md)

## Test

```bash
npm test
```

Vitest unit tests mock KV and R2 bindings for routing and SPA fallback behavior.

## Related

- [Edge roadmap](../../docs/edge-roadmap.md)
- [Edge product boundary ADR](../../docs/adr/edge-product-boundary.md)
- Laravel config: `config/edge.php`
