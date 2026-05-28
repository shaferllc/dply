# Tier B workflows

Status: **in progress** · Branch: `feature/tier-b-workflows`

Tier B ideas from [`DIFFERENTIATION_IDEAS.md`](DIFFERENTIATION_IDEAS.md) are **true differentiators** — medium effort, hard for single-lane competitors to copy. This doc tracks workflow implementation progress.

## Shipped / in flight

### Full-stack from one repo (`launch.full_stack_wizard`)

**Route:** `/launches/full-stack` · **Flag:** `launch.full_stack_wizard` (requires `surface.cloud` + `surface.edge` + `multi_surface_active()`)

**Flow:**

1. **Repository** — operator pastes Git URL + branch
2. **Architecture** — `FullStackArchitecturePlanner` shallow-clones, runs monorepo + runtime detection, returns recommended layers
3. **Wiring** — cross-engine hints (origin order, DATABASE_URL, firewall)

**Handoffs:** each layer links to the appropriate create flow with query prefills:

| Layer | Create route | When |
|-------|--------------|------|
| Edge front | `edge.create` | Static or hybrid frontend |
| Cloud origin | `edge.create` (hybrid stack) | SSR / hybrid Node apps |
| BYO API | `servers.create` | PHP / Laravel backends |
| Database host | `servers.create` | Optional full-stack data layer |

**Code:**

- [`app/Services/Launch/FullStackArchitecturePlanner.php`](../app/Services/Launch/FullStackArchitecturePlanner.php)
- [`app/Livewire/Launches/FullStack.php`](../app/Livewire/Launches/FullStack.php)
- [`app/helpers.php`](../app/helpers.php) — `full_stack_wizard_active()`

**Enable:**

```env
FEATURE_LAUNCH_FULL_STACK_WIZARD=true
FEATURE_SURFACE_CLOUD=true
FEATURE_SURFACE_EDGE=true
```

---

### `dply.yaml` everywhere — BYO sync (`global.byo_repo_config`)

**Flag:** `global.byo_repo_config`

After each BYO VM git deploy, Dply fetches `dply.yaml` / `dply.yml` / `dply.json` from the live checkout over SSH and syncs:

| Section | Applied to |
|---------|------------|
| `redirects` / `rewrites` | `site_redirects` (comment `dply.yaml`) |
| `crons` with `command` | `server_cron_jobs` on the site’s server |
| `deploy_hooks` | `site_deploy_hooks` (managed script prefix) |

**UI:** Site → Repository tab shows last sync snapshot via [`ByoRepoConfigPanel`](../app/Livewire/Sites/ByoRepoConfigPanel.php).

**Example:**

```yaml
redirects:
  - from: /legacy/*
    to: /docs/:splat
    status: 301

crons:
  - schedule: "0 * * * *"
    command: "cd /home/dply/app && php artisan schedule:run"

deploy_hooks:
  - phase: after_clone
    script: |
      composer install --no-dev -o
```

**Code:**

- [`app/Services/Sites/ByoRepoConfigLoader.php`](../app/Services/Sites/ByoRepoConfigLoader.php)
- [`app/Services/Sites/ByoRepoConfigSync.php`](../app/Services/Sites/ByoRepoConfigSync.php)
- Hooks in [`SiteGitDeployer`](../app/Services/Sites/SiteGitDeployer.php) + [`AtomicSiteDeployer`](../app/Services/Sites/AtomicSiteDeployer.php)

**Enable:**

```env
FEATURE_GLOBAL_BYO_REPO_CONFIG=true
```

**Follow-ups:** auto-queue webserver reload after redirect sync; notification rules block; Cloud/BYO shared env section.

---

### Deploy replay / shadow traffic (`global.edge_deploy_replay`)

**Flag:** `global.edge_deploy_replay`

Sample recent production **GET/HEAD** paths from `edge_access_logs`, then HTTP-replay them against a **live preview URL** before promote or split traffic.

**UI:** Edge workspace → **Previews** → **Shadow replay** on each live preview row.

**Code:**

- [`app/Services/Edge/EdgeDeployReplaySampler.php`](../app/Services/Edge/EdgeDeployReplaySampler.php)
- [`app/Services/Edge/EdgeDeployReplayRunner.php`](../app/Services/Edge/EdgeDeployReplayRunner.php)
- [`app/Actions/Edge/QueueEdgeDeployReplay.php`](../app/Actions/Edge/QueueEdgeDeployReplay.php)
- [`app/Jobs/RunEdgeDeployReplayJob.php`](../app/Jobs/RunEdgeDeployReplayJob.php)

**Enable:**

```env
FEATURE_GLOBAL_EDGE_DEPLOY_REPLAY=true
```

**Follow-ups:** POST replay with sanitized bodies; bypass preview password for internal replay; tie into promote confirmation modal.

---

## Tier B backlog (not started)

| Idea | Suggested branch | Notes |
|------|------------------|-------|
| Transparent cost observatory | `feature/tier-b-cost-observatory` | Org billing + provider estimates |
| Preview review hub | `feature/tier-b-preview-review` | Expand `EdgePreviewComments` |
| Runbook marketplace | `feature/tier-b-marketplace` | Needs `surface.marketplace` GA |
| Ephemeral deploy credentials | `feature/tier-b-ephemeral-ssh` | Per-deploy key lifecycle |

---

## Related

- [`DIFFERENTIATION_IDEAS.md`](DIFFERENTIATION_IDEAS.md) — full idea backlog + scoring
- [`edge-roadmap-next.md`](edge-roadmap-next.md) — Edge Waves E+
- [`CreateHybridEdgeStack`](../app/Actions/Edge/CreateHybridEdgeStack.php) — hybrid provisioning action
