# dply Edge roadmap — next phases

Status: planning (2026-05-24)

Continuation of [edge-roadmap.md](edge-roadmap.md). Phases 0–4 shipped the core deploy loop, custom domains, and hybrid SSR. The phases below take Edge from "works" to "feels finished" and competitive with Vercel / Netlify / Cloudflare Pages.

Ordering is roughly by dependency, not strict priority. The top-5 must-haves (P5, P6, P7, P9, P14) close the "feels finished" gap. P14 is the single biggest acquisition lever.

## Phase 5 — Programmatic surface (CLI + Public API)

**Goal:** every UI action is scriptable. Power users, CI pipelines, and external integrations can drive Edge without the dashboard.

### 5a — Public REST API

- New `routes/api.php` group under `/api/v1/edge/*`, token-scoped (Sanctum personal access tokens with `edge:read` / `edge:write` abilities)
- Resources: `sites`, `deployments`, `domains`, `env`, `aliases`, `bindings`, `logs`, `usage`
- All endpoints return JSON resources; use Eloquent API Resources (`app/Http/Resources/Edge/*`)
- Rate limit per token (default 600/min)
- OpenAPI spec generated to `public/openapi/edge.json`

**Touchpoints:** `app/Http/Controllers/Api/Edge/*`, `app/Http/Resources/Edge/*`, `app/Policies/EdgeSitePolicy.php`, `routes/api.php`

**Acceptance:** `curl -H "Authorization: Bearer …" .../api/v1/edge/sites` lists user's edge sites; `POST /deployments` triggers a deploy; round-trips match the dashboard.

### 5b — `dply` CLI (Node, distributed via npm)

New repo path: `packages/dply-cli/`. Single binary, ships as `npm i -g dply`.

Commands (Edge subset):

| Command | Purpose |
| --- | --- |
| `dply login` | OAuth device-flow to get an API token |
| `dply link` | Link cwd to an Edge site (writes `.dply/site.json`) |
| `dply edge deploy [--prod]` | Trigger a deploy (preview by default) |
| `dply edge logs --tail [--deploy <id>]` | Stream live request + build logs |
| `dply edge env [pull \| push \| set KEY=val \| rm KEY] [--env prod\|preview]` | Manage env vars and secrets |
| `dply edge rollback <deploy-id>` | Re-point production at an existing deploy |
| `dply edge promote <deploy-id>` | Promote a preview to production |
| `dply edge aliases [list \| add \| rm]` | Manage per-deploy aliases |
| `dply edge domains [list \| add \| rm \| verify]` | Custom domains |
| `dply edge purge [--all \| --path /foo]` | Purge cache |
| `dply edge open` | Open the site / dashboard in browser |
| `dply edge whoami` | Show active token + org |

**Touchpoints:** new `packages/dply-cli/`, `app/Http/Controllers/Api/Auth/DeviceFlowController.php`

**Acceptance:** `dply link && dply edge deploy --prod` from a fresh checkout deploys and prints the prod URL.

---

## Phase 6 — `dply.toml` in-repo config

**Goal:** declarative build + routing + bindings config committed to the repo. No more "remember to set this in the dashboard."

### Schema (v1)

```toml
[build]
command   = "npm run build"
output    = "dist"
root      = "apps/web"      # monorepo support
node      = "20"
env_files = [".env.production"]

[[redirects]]
from   = "/old/*"
to     = "/new/:splat"
status = 301

[[rewrites]]
from = "/api/*"
to   = "https://api.example.com/:splat"

[[headers]]
for     = "/static/*"
[headers.values]
"Cache-Control" = "public, max-age=31536000, immutable"

[functions]
middleware = "src/middleware.ts"

[bindings.kv]
SESSIONS = "kv_namespace_id_here"

[bindings.r2]
ASSETS = "bucket-name"
```

### Deliverables

- `app/Services/Edge/Config/EdgeRepoConfigLoader.php` — parses `dply.toml` at build time, validates, returns DTOs
- New models: `EdgeRedirect`, `EdgeRewrite`, `EdgeHeaderRule` (mirrored to DB so Worker has fast lookup; toml is source of truth)
- Worker (`packages/edge-worker`) reads rules from KV and applies before R2 lookup
- Build runner: dashboard env merges with `[build.env_files]`; dashboard wins on conflict
- Lint command: `dply edge lint` (also runs server-side on every deploy, surfaces in build log)

**Touchpoints:** `app/Services/Edge/Config/`, `app/Services/Edge/EdgeBuildRunner.php`, `packages/edge-worker/src/router.ts`

**Acceptance:** committing a `dply.toml` with redirects → next deploy honors them; UI shows "managed by dply.toml" badge on the redirects tab.

---

## Phase 7 — Deploy lifecycle UX

Backend rollback exists; surface it. Plus the missing primitives users expect.

### 7a — Rollback + promote in UI

- New buttons on `app/Livewire/Sites/EdgeSettings.php` and the deployment row partial:
  - **Promote to production** (preview deploys only)
  - **Rollback** (production history list, "make this current")
- Confirmation modal with diff: which commit, which env vars, which deploy ID
- Action: `app/Actions/Edge/RollbackEdgeDeployment.php` (wraps existing `EdgeBackend::repoint()`)

### 7b — Per-deploy stable aliases

Every successful deploy gets a permanent alias: `{commit-sha-short}.{site}.dply.host` and `{deploy-id}.{site}.dply.host`. Outlives the PR.

- Add `aliases jsonb` column to `edge_deployments`
- `EdgeHostMapPublisher` writes alias → deployment mapping into KV
- Worker resolves alias hostnames same as PR previews
- UI: "Aliases" tab on deployment detail with copy buttons

**Touchpoints:** new migration, `app/Services/Edge/EdgeHostMapPublisher.php`, `packages/edge-worker/src/router.ts`

### 7c — Password-protected previews

Per-site toggle: gate all non-production hostnames behind a password.

- Settings UI: `EdgeSettings` → "Preview protection" section (off / password / dply-account)
- New table `edge_site_access_rules` (mode, hashed password, allowed_emails)
- Worker reads rule from KV; serves a lightweight HTML auth page; sets signed cookie on success (HS256, 24h)
- Account-mode validates dply session JWT (shared secret with main app)

**Touchpoints:** new migration + model `EdgeSiteAccessRule`, `app/Services/Edge/EdgeAccessGate.php`, `packages/edge-worker/src/auth.ts`

**Acceptance:** flip toggle → preview URL prompts for password; production URL unaffected.

---

## Phase 8 — Monorepo & build cache

### 8a — Root directory + multi-site-per-repo

- `edge_sites.repo_root` column (already partly there? verify)
- Create flow: detect monorepo (presence of `pnpm-workspace.yaml`, `turbo.json`, `nx.json`, `lerna.json`) and offer a subdirectory picker
- Build runner `cd`s into root before running build command
- GitHub webhook: only redeploy if changed files touch `repo_root/**` or `dply.toml`

### 8b — Build cache

- Per-site cache stored in R2 at `{prefix}/cache/{cache-key}.tar.zst`
- Cache key: hash of `package-lock.json`/`pnpm-lock.yaml`/`yarn.lock` + node version + `repo_root`
- Cached paths (framework-aware presets): `node_modules`, `.next/cache`, `.nuxt`, `.astro`, `.svelte-kit/output`, `.cache`, `dist/.cache`
- Restore before build, snapshot after build (background, doesn't block deploy completion)
- Cap: 500 MB per site (config); LRU eviction

**Touchpoints:** `app/Services/Edge/EdgeBuildRunner.php`, new `app/Services/Edge/EdgeBuildCache.php`

**Acceptance:** second build of a Next.js app is ≥3× faster than first.

---

## Phase 9 — Live observability

### 9a — Real-time log tail (UI + CLI)

You already ingest logs (`EdgeLogIngestController`, `EdgeLogpushIngestController`); surface them live.

- Laravel Reverb / Pusher channel: `edge.site.{id}.logs`
- `EdgeLogIngestController` broadcasts each line as it arrives
- New Livewire component `app/Livewire/Sites/EdgeLogs.php` with a filter bar (level, status, path, deploy)
- CLI `dply edge logs --tail` subscribes via WebSocket
- 7-day retention in `edge_access_logs`; "download last hour" CSV export

### 9b — Deploy notifications

Per-site notification rules.

- New table `edge_notification_rules` (channel, target, event_mask)
- Channels: email, Slack webhook, Discord webhook, generic webhook
- Events: deploy.success, deploy.failed, deploy.duration_regressed (>1.5× rolling p50), domain.verified, domain.failing, usage.over_budget
- Wired via `App\Events\Edge\*` → `App\Listeners\DispatchEdgeNotification`
- UI: settings tab with "Test" button per rule

### 9c — Audit log

- New table `edge_audit_log` (actor_id, site_id, action, target, before, after, ip, ua)
- Capture: env changes, domain add/remove, deploys, rollbacks, promotes, access rule changes, member changes
- UI tab + CSV export; 90-day retention default

**Touchpoints:** new models + migrations, `app/Listeners/Edge/*`, `resources/views/livewire/sites/edge-logs.blade.php`

---

## Phase 10 — Edge compute (the Cloudflare moat)

### 10a — Middleware / functions

User-written code on the request path. Single file per site to start: `src/middleware.ts`.

- Build runner detects middleware entry, bundles with esbuild, uploads as a per-deployment Worker module
- Bindings injected from `dply.toml` `[bindings.*]`
- Routing: middleware runs before R2 lookup; can short-circuit (auth), rewrite (`Request` → `Request`), or pass through
- Local dev: `dply edge dev` (Node-based runner using Miniflare)

**Touchpoints:** `app/Services/Edge/EdgeBuildRunner.php` (middleware bundling), `app/Console/Commands/EdgeWorkerDeployCommand.php` (already exists), `packages/edge-worker/src/middleware-host.ts` (new)

### 10b — Cron triggers + deploy hooks

- `[crons]` section in `dply.toml`: schedule + handler path
- Cloudflare cron triggers via API on the per-site Worker
- Deploy hooks: per-site URL `https://hooks.dply.host/edge/{token}` → triggers redeploy (Sanity, Contentful, Strapi integrations)

### 10c — Bindings UI

Per-site Cloudflare resource management.

- New tab on Edge workspace: "Bindings"
- KV namespaces, R2 buckets, D1 databases, Queues
- Create / attach existing / detach; auto-injects into `dply.toml` on save (writes a PR if repo connected, or notes "add to dply.toml manually")
- Per-binding usage metrics from Cloudflare GraphQL Analytics

**Touchpoints:** `app/Livewire/Sites/EdgeBindings.php`, `app/Services/Edge/EdgeBindingsManager.php`, extend `EdgeCloudflareClient`

### 10d — Split traffic / A-B at edge

- Production "split" config: route N% to a specific preview deployment
- Sticky-by-cookie option (so a user sees consistent variant)
- UI: slider on deployment detail "Send X% of prod traffic here"
- Worker reads split config from KV before R2 lookup; logs variant served per request

**Acceptance:** set 10% split → ~10% of prod traffic hits the preview, sticky per visitor.

---

## Phase 11 — Acquisition: import + templates

### 11a — Import from Vercel / Netlify / Pages

Single biggest churn lever vs incumbents. Three importers, one wizard.

- New flow at `/edge/import/{provider}`
- Vercel: OAuth → list projects → pull `vercel.json`, env vars, build command, framework, domains
- Netlify: PAT input → list sites → pull `netlify.toml`, `_redirects`, `_headers`, env vars, build, domains
- Pages: CF API token (often already linked) → list projects → pull build config, env, custom domains
- Translates source config → `dply.toml`, opens a PR against the user's repo with the file
- Imports env vars directly (secrets stay encrypted)
- Optional: sets up DNS swap instructions per provider

**Touchpoints:** new `app/Services/Edge/Importers/{Vercel,Netlify,Pages}Importer.php`, `app/Livewire/Edge/Import.php`

### 11b — Framework presets + auto-detect

Make the create flow zero-config for the common cases.

- `app/Services/Edge/Frameworks/FrameworkDetector.php` — sniffs `package.json` + lockfile + framework files
- Presets: Next.js, Astro, SvelteKit, Remix, Nuxt, Hono, Vite, Eleventy, Hugo, Jekyll, plain static
- Each preset declares: build command, output dir, node version, runtime mode (static/hybrid/ssr), cache paths
- Surfaced in create flow as "Detected: Astro → Build settings preconfigured"

### 11c — Template gallery

- New page `/edge/templates`: curated list of starter repos with "Deploy with dply" button
- Each template = a metadata file in `database/seeders/EdgeTemplateSeeder.php` (slug, repo URL, framework, description, screenshot)
- Click → if logged out, sign-up wall → fork user's GitHub → create Edge site → first deploy auto-triggers
- Launch with 10 templates (Next blog, Astro docs, SvelteKit SaaS starter, Hono API, Remix shop, plain static portfolio, etc.)
- Public "Deploy" badge generator: README markdown snippet pointing at `/edge/templates/deploy?repo=…`

---

## Phase 12 — Team collaboration on Edge sites

Org membership already exists ([ORG_ROLES_AND_LIMITS.md](ORG_ROLES_AND_LIMITS.md)); add per-site granularity.

- New table `edge_site_members` (site_id, user_id, role)
- Roles: `viewer` (read-only + logs), `deployer` (deploy + env), `admin` (everything incl. members + billing)
- Policy: `EdgeSitePolicy@deploy`, `@manageEnv`, `@manageDomains`, `@manageMembers`
- UI: "Members" tab on Edge workspace
- Invite by email; pending invites table

**Touchpoints:** new migration + model, `app/Policies/EdgeSitePolicy.php`, `app/Livewire/Sites/EdgeMembers.php`

---

## Sequencing recommendation

| Wave | Phases | Why together |
| --- | --- | --- |
| Wave A (closes "feels finished") | 5a + 5b, 6, 7a, 7b | API + config + lifecycle are mutually reinforcing; ship together |
| Wave B (table-stakes polish) | 7c, 8a, 8b, 9a | What evaluators hit on day 2 |
| Wave C (acquisition push) | 11a, 11b, 11c | After waves A+B, the product is worth migrating to |
| Wave D (differentiation) | 10a, 10b, 10c, 10d | Cloudflare moat — needs A+B foundation |
| Wave E (org maturity) | 9b, 9c, 12 | Required to land paying teams |

## Open questions

- CLI distribution: npm-only, or also Homebrew / curl-installer? (Vercel ships all three.)
- `dply.toml` vs `dply.json` vs `dply.config.ts`? TOML chosen here for human-friendliness; revisit if TS-config users push back.
- Should split-traffic (10d) work for *any* preview, or require an explicit "release candidate" tag? Safety vs flexibility.
- Audit log retention: 90 days default — does compliance/enterprise tier need configurable longer?
- Import-from-Pages (11a) — Pages and dply both sit on Cloudflare. Co-existing or replacing? Affects DNS swap UX.
