# Tier C — Moonshot workflows

Bold, longer-horizon differentiators from [`DIFFERENTIATION_IDEAS.md`](DIFFERENTIATION_IDEAS.md). Tier B items ship first; Tier C is headline / platform-defining work.

---

## Backlog

| Idea | Score | Flag / gate | Status |
|------|-------|-------------|--------|
| **Blast-radius graph** | 16 | `surface.fleet` | **Shipped (v1)** |
| **Ops Copilot** | 15 | `global.ops_copilot` | **Shipped (v1 heuristics)** |
| **Multi-cloud standby blueprints** | — | `launch.standby_blueprint` | **Shipped (v1)** |
| **Edge + BYO unified preview URLs** | — | none (always on) | **Shipped (v1)** |
| Serverless as glue | — | `surface.serverless` | **Shipped (v1)** |

---

## Blast-radius graph (`surface.fleet`)

**Route:** `/fleet/blast-radius` · **Tab:** Fleet → **Blast radius**

Visual dependency map across the org inventory — answer “what breaks if X fails?”

### v1 scope

| Layer | Nodes | Edges |
|-------|-------|-------|
| Infrastructure | BYO **servers**, **databases** | server → database |
| Applications | BYO / **Cloud** / **Serverless** sites | server → site |
| Edge | **Edge** sites | Cloud origin → Edge (hybrid `meta.edge.origin.cloud_site_id`), Cloud ↔ Edge stack pair |

**Impact simulation:** pick any node → lists transitive dependents (downstream blast radius).

**Not in v1:** DNS, external SaaS, env-var DB bindings, cross-org links.

### Code

- [`app/Services/Fleet/BlastRadiusGraph.php`](../app/Services/Fleet/BlastRadiusGraph.php) — graph builder + impact query
- [`app/Livewire/Fleet/BlastRadius.php`](../app/Livewire/Fleet/BlastRadius.php)
- [`resources/views/livewire/fleet/blast-radius.blade.php`](../resources/views/livewire/fleet/blast-radius.blade.php)

### Enable

Requires Fleet (`FEATURE_SURFACE_FLEET=true`, default on) — same gate as Health / Deploys / Intelligence.

---

## Ops Copilot (`global.ops_copilot`)

**Route:** `/fleet/copilot` · **Tab:** Fleet → **Copilot** (when flag on)

Cross-engine deploy failure triage — assembles the latest BYO or Edge failure log, repo config snapshot, deploy intelligence alerts, and server saved commands, then emits heuristic fix suggestions.

### v1 scope

| Input | Source |
|-------|--------|
| Failure log | Latest failed `site_deployments.log_output` or Edge `build_log` |
| Repo config | Edge `repo_config` on deployment or site meta |
| Intelligence | Open `deploy_intelligence_alerts` for the site |
| Runbook hints | Server `server_recipes` names |

**Suggestions:** regex heuristics in `config/dply_ops_copilot.php` (memory limits, npm ERESOLVE, missing modules, DB connection, APP_KEY, etc.). Optional LLM hook stubbed via `DPLY_OPS_COPILOT_LLM_*` env — not wired in v1 UI.

### Code

- [`app/Services/OpsCopilot/OpsCopilotContextBuilder.php`](../app/Services/OpsCopilot/OpsCopilotContextBuilder.php)
- [`app/Services/OpsCopilot/OpsCopilotAdvisor.php`](../app/Services/OpsCopilot/OpsCopilotAdvisor.php)
- [`app/Livewire/Fleet/OpsCopilot.php`](../app/Livewire/Fleet/OpsCopilot.php)

### Enable

```bash
php artisan feature:set global.ops_copilot --on --reason="Ops Copilot dogfood"
```

Requires **Fleet** (`surface.fleet`) for the tab shell. Toggle also appears on `/admin` under **Product**.

---

## Multi-cloud standby blueprints (`launch.standby_blueprint`)

**Route:** `/launches/standby` · **Launchpad tile:** **Standby blueprints**

Opinionated failover playbooks merged with org inventory — Edge hybrid origin swap, BYO standby server cutover, DNS TTL cutover. Not full HA; honest actionable steps with deep links.

### v1 blueprints

| Key | Scenario | Requires |
|-----|----------|----------|
| `edge_hybrid_origin` | Swap linked Cloud / external SSR origin | Hybrid Edge site w/ origin |
| `byo_standby_server` | Warm standby VM + env sync + cutover | BYO VM site |
| `dns_cutover` | TTL lowering + A/CNAME swap | Custom domain on a site |

**Inventory-aware:** lists hybrid Edge stacks, BYO servers/sites, Cloud origins, and custom domains; surfaces gaps (e.g. only one BYO server).

### Code

- [`config/standby_blueprints.php`](../config/standby_blueprints.php)
- [`app/Services/Launch/StandbyBlueprintPlanner.php`](../app/Services/Launch/StandbyBlueprintPlanner.php)
- [`app/Livewire/Launches/StandbyBlueprint.php`](../app/Livewire/Launches/StandbyBlueprint.php)

### Enable

```bash
php artisan feature:set launch.standby_blueprint --on --reason="Standby blueprint dogfood"
```

Also on `/admin` under **Launch**. Links to marketplace runbooks when `surface.marketplace` is on.

---

## Edge + BYO unified preview URLs (no flag)

**Route:** `/fleet/previews` · **Tab:** Fleet → **Previews**

One hostname pattern for managed previews across BYO VM sites and Edge delivery:

| Type | Pattern | Example |
|------|---------|---------|
| Primary | `{slug}-{idHash8}.{apex}` | `api-a1b2c3d4.on-dply.site` |
| Branch / PR | `{parentLabel}--{branch\|pr-n}.{apex}` | `api-a1b2c3d4--pr-42.on-dply.site` |

BYO provisioning prefers **on-dply.*** apex zones from the shared testing pool when configured (`config/preview.php`).

### Code

- [`app/Support/Preview/UnifiedPreviewHostname.php`](../app/Support/Preview/UnifiedPreviewHostname.php)
- [`app/Services/Fleet/UnifiedPreviewCatalog.php`](../app/Services/Fleet/UnifiedPreviewCatalog.php)
- [`app/Livewire/Fleet/Previews.php`](../app/Livewire/Fleet/Previews.php)
- Wired into [`TestingHostnameProvisioner`](../app/Services/Sites/TestingHostnameProvisioner.php), [`Site::edgeHostname()`](../app/Models/Site.php), [`CreateEdgePreviewSite`](../app/Actions/Edge/CreateEdgePreviewSite.php)

Requires **Fleet** (`surface.fleet`) for the catalog UI.

---

## Serverless as glue (`surface.serverless`)

**Route:** `/serverless/glue` · **Link:** Serverless index → **Glue**

OpenWhisk sequences as multi-engine orchestration — recipe playbooks merged with org inventory (Edge deploy hooks, code actions, Cloud redeploy URLs, BYO crons) plus an in-app sequence builder and deploy control.

### v1 recipes

| Key | Pattern | Requires |
|-----|---------|----------|
| `edge_webhook_pipeline` | Edge deploy hook → serverless sequence | Edge hook + 2+ code actions |
| `cloud_redeploy_chain` | Sequence → Cloud redeploy webhook | Cloud app + 2+ code actions |
| `byo_cron_callback` | Sequence → BYO cron callback | BYO cron + 2+ code actions |
| `multi_engine_orchestration` | Edge → serverless → Cloud → BYO | All of the above |

**Sequences tab:** pick a functions host namespace, define ordered code-action components via `ServerlessSequenceBuilder`, deploy via `ServerlessSequenceDeployer`.

### Code

- [`config/serverless_glue.php`](../config/serverless_glue.php)
- [`app/Services/Serverless/ServerlessGlueInventory.php`](../app/Services/Serverless/ServerlessGlueInventory.php)
- [`app/Services/Serverless/ServerlessGluePlanner.php`](../app/Services/Serverless/ServerlessGluePlanner.php)
- [`app/Livewire/Serverless/Glue.php`](../app/Livewire/Serverless/Glue.php)

### Enable

Requires **Serverless** surface (`FEATURE_SURFACE_SERVERLESS=true` or per-org Pennant). Same gate as `/serverless`.

---

## Related

- [`TIER_B_WORKFLOW.md`](TIER_B_WORKFLOW.md) — shipped Tier B differentiators
- [`DIFFERENTIATION_IDEAS.md`](DIFFERENTIATION_IDEAS.md) — full scoring + Tier C definitions
