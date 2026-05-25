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
- Customer repo must declare a supported framework + its Cloudflare adapter (see table below)

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

## Supported frameworks

`EdgeBuildRunner` reads `app/Services/Edge/Ssr/EdgeSsrFrameworkRegistry`
on every SSR deploy. Detection looks at `package.json` for the entries
in the **Detected** column; the **Adapter** column is the package that
must also be installed (or the build fails with a clear error).

| Framework | Detected | Adapter | dply runs | Worker output | Assets dir |
| --- | --- | --- | --- | --- | --- |
| Next.js | `next` | (built-in) | `npx --yes @opennextjs/cloudflare@latest build` | `.open-next/worker.js` | `.open-next/assets` |
| SvelteKit | `@sveltejs/kit` | `@sveltejs/adapter-cloudflare` | your `build_command` | `.svelte-kit/cloudflare/_worker.js/` | `.svelte-kit/cloudflare` |
| Astro | `astro` | `@astrojs/cloudflare` | your `build_command` | `dist/_worker.js/` | `dist` |
| Remix | `@remix-run/cloudflare` | `@remix-run/cloudflare` | your `build_command` | `build/server/index.js` | `build/client` |

Only Next.js has its build command overridden — OpenNext owns the full
pipeline there. For the other three, dply runs whatever `build_command`
you configured (defaulting to `npm run build`) after `npm install` and
expects the worker output file/dir to land at the path above.

Multi-module worker bundles (Astro / SvelteKit dump a directory of
helper modules alongside the entry) ship as one dispatch script with
all `.js` / `.mjs` / `.wasm` modules attached. Total bundle is capped
at 9 MB to stay under Cloudflare's Workers script limit.

Add a framework: drop a new `EdgeSsrFrameworkProfile` into the
registry. No other changes needed — detection, build, and upload all
read from there.

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
| Build fails with "SSR Edge sites need one of: Next.js, Astro, SvelteKit, or Remix" | Repo has none of the supported framework deps — use static or hybrid mode, or add the framework + its Cloudflare adapter |
| Build fails with "X needs `@…/cloudflare` in package.json" | Framework is detected but the Cloudflare adapter isn't installed — `npm install @astrojs/cloudflare` (or whichever) and redeploy |
| Dispatch namespace upload returns 403 | API token is missing the Workers for Platforms scope — generate a new token with **Account → Workers Scripts: Edit** + **Account → Workers for Platforms: Edit** |
