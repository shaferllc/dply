# dply Edge — Worker-native SSR (Phase 4b)

Status: shipped (2026-05-24)

dply Edge runs Next.js apps directly on Cloudflare Workers via
`@opennextjs/cloudflare`. Each deployment uploads its own immutable
Worker script into a Workers for Platforms dispatch namespace; the
public `dply-edge` Worker hands matching traffic off via a service
binding. No public `*.workers.dev` URL is exposed — the dispatched
script is callable only through dispatch.

## Architecture

```
Visitor → Cloudflare wildcard route (*.on-dply.site/*)
         │
         ▼
   Platform Worker (dply-edge)            ← packages/edge-worker
         │
         │ hostEntry.runtime_mode === 'ssr'
         │ hostEntry.ssr_worker_script === 'dply-ssr-…'
         ▼
   env.DISPATCHER.get(scriptName).fetch(request)
         │
         ▼
   Per-deployment SSR Worker             ← dispatch namespace
   (worker.js from .open-next/, bound to ASSETS=R2 + HOST_MAP=KV)
```

For `static` and `hybrid` sites the platform Worker keeps doing what
it always did — serve from R2, proxy to the configured origin. SSR is
an additional code path, not a replacement.

## Prerequisites

- Workers Paid plan on the Cloudflare account
- API token with the **Workers Scripts: Edit** + **Workers for Platforms: Edit** scopes (the bootstrap command spells out the exact scopes needed in its error output when missing)
- Customer repo must list `next` in `package.json`

## Operator bootstrap

```sh
php artisan dply:edge:infra:bootstrap
```

Creates R2 bucket + KV namespaces (host map + edge cache) **and** the
dispatch namespace `dply-edge-ssr` (override with `--dispatch-name=…`).
Prints the `DPLY_EDGE_CF_DISPATCH_NAMESPACE` + `…_DISPATCHE_NAMESPACE_ID`
env lines you need to add to production. Pass `--skip-dispatch` to
opt out (SSR sites will be blocked).

Re-deploy the platform Worker so the dispatch binding lands in its
config:

```sh
php artisan edge:worker:deploy
```

Verify:

```sh
php artisan dply:edge:doctor --probe
```

## Customer-side requirements

The repository must produce a Next.js build that OpenNext can wrap:

- `next` in `package.json` dependencies (sniffed by `EdgeBuildRunner`)
- Standard Next.js project layout (`next.config.js`, `pages/` or `app/`)
- No custom `next build` overrides that produce non-Node output

dply ignores the dashboard `build.command` / `build.output_dir` for
SSR sites — OpenNext owns both. Build command becomes:

```
<install>  &&  npx --yes @opennextjs/cloudflare@latest build
```

and the assets layer always lives at `.open-next/assets/`.

## What happens per deploy

1. `BuildEdgeSiteJob` clones the repo, runs the OpenNext build in
   Docker (`node:20-bookworm`), reads `.open-next/worker.js` into
   memory, and writes a JSON sidecar with the bundled module source.
2. `PublishEdgeDeploymentJob` calls `EdgeSsrBundleUploader` which
   ships the worker.js into the dispatch namespace at
   `dply-ssr-{site-id-tail6}-{deploy-id-tail8}` with bindings:
   - `HOST_MAP` (KV)
   - `ASSETS` (R2)
   - `DEPLOYMENT_ID`, `SITE_ID`, `STORAGE_PREFIX` (plain text)
3. Static assets get uploaded to R2 the same way they do for static
   sites — the per-deployment Worker reads them via the `ASSETS`
   binding.
4. `EdgeHostMapPublisher` writes the KV routing entry with
   `runtime_mode: 'ssr'` + `ssr_worker_script: '...'` so the platform
   Worker knows to dispatch.

## Rollback / promote semantics

Identical to static + hybrid sites:

- **Rollback** flips KV back at a prior deployment whose script still
  exists in the namespace. No script re-upload needed.
- **Promote** copies the preview's R2 prefix and emits a fresh KV
  entry pointing at the preview's existing dispatch script — also no
  re-upload.

This means dispatch scripts effectively get the same retention window
as R2 artifacts (`releases_to_keep`). When a deployment is pruned its
matching SSR + middleware scripts are deleted from the dispatch
namespace in lockstep — see `EdgeDeploymentPruner` and the
`deleteScriptForDeployment()` helpers on each uploader.

## Teardown

`TeardownEdgeSiteJob` calls `EdgeSsrBundleUploader::deleteAllForSite`
before wiping the rows, so the dispatch namespace doesn't accumulate
orphans when sites are deleted. Failures are best-effort logged.

## Known gaps

- **Per-deploy script pruning** — DONE. `EdgeDeploymentPruner` now
  calls `EdgeSsrBundleUploader::deleteScriptForDeployment` +
  `EdgeMiddlewareBundleUploader::deleteScriptForDeployment` for every
  deployment it prunes, so dispatch scripts age out alongside R2
  artifacts on the `releases_to_keep` window. Delete failures are
  logged and do not block subsequent prunes.
- **Framework support** — only Next.js (via OpenNext). Astro adapters
  + SvelteKit can land later; the build runner just needs the
  framework-specific incantation to produce a Worker bundle.
- **Custom env vars** — env binding wiring will arrive with the env
  var feature in Wave B. For now the script only sees the deploy
  identity bindings + KV + R2.
- **Build cache** — first OpenNext build is slow (5–10 min for a
  typical Next app); subsequent builds repeat the work. Build cache
  (Phase 8) will fix.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| "SSR Edge sites need a Workers for Platforms dispatch namespace" on create | Bootstrap not run, or `DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID` missing from `.env` |
| Build fails with "SSR build did not produce .open-next/worker.js" | OpenNext build hit an error — read the build log for the underlying message (often `next build` failing) |
| Browser sees "Service temporarily unavailable — SSR worker not reachable." | Platform Worker has no `DISPATCHER` binding — re-run `php artisan edge:worker:deploy` after bootstrap added the dispatch namespace |
| Build fails with "SSR Edge sites currently support Next.js only" | Repo has no `next` in `package.json` — use static or hybrid mode |
| Dispatch namespace upload returns 403 | API token is missing the Workers for Platforms scope — generate a new token with **Account → Workers Scripts: Edit** + **Account → Workers for Platforms: Edit** |
