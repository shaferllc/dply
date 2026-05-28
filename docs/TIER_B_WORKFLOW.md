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

### Transparent cost observatory (`global.billing_enabled`)

**Flag:** `global.billing_enabled` (same gate as pricing CTAs)

Org **Billing analytics** adds a **Cost observatory** panel combining:

| Layer | Source |
|-------|--------|
| Dply platform | `DesiredBillingState` (base + tiers + managed + Edge usage) |
| Provider infrastructure | Saved server cost notes or live catalog lookup (`ServerProviderCostEstimator`) |
| Comparison | Laravel Forge Hobby baseline ($12/server/mo) + same provider estimates |

**Code:**

- [`app/Services/Billing/OrganizationCostObservatory.php`](../app/Services/Billing/OrganizationCostObservatory.php)
- [`app/Services/Billing/ServerMonthlyCostNoteParser.php`](../app/Services/Billing/ServerMonthlyCostNoteParser.php)
- Wired into [`BillingAnalytics`](../app/Services/Billing/BillingAnalytics.php) → [`billing/analytics`](../app/Livewire/Billing/Analytics.php)

**Enable:**

```env
FEATURE_GLOBAL_BILLING_ENABLED=true
```

**Follow-ups:** Cloud/App Runner provider pass-through estimates; annual commit amortization; currency conversion from live rates.

---

### Preview review hub

**Route:** `/servers/{server}/sites/{preview}/preview-comments` (preview child sites)

Expands preview comments into a **PR-linked review hub**:

| Capability | Detail |
|------------|--------|
| PR link | GitHub pull request URL from parent repo + `preview_pr_number` |
| Threads | `parent_id` on `edge_preview_comments` + dashboard replies |
| Approvals | `edge_preview_review_approvals` — per-user sign-off with optional note |
| Promote gate | `confirmPromoteEdgePreview` + review hub promote respect open comments / required approvals |

**Code:**

- [`app/Services/Edge/EdgePreviewReviewState.php`](../app/Services/Edge/EdgePreviewReviewState.php)
- [`app/Livewire/Sites/EdgePreviewComments.php`](../app/Livewire/Sites/EdgePreviewComments.php) — review hub UI
- [`app/Support/Edge/EdgePreviewPullRequestLink.php`](../app/Support/Edge/EdgePreviewPullRequestLink.php)

**Config:**

```env
DPLY_EDGE_PREVIEW_REVIEW_MIN_APPROVALS=1
DPLY_EDGE_PREVIEW_REVIEW_REQUIRE_APPROVAL=false
DPLY_EDGE_PREVIEW_REVIEW_BLOCK_OPEN_COMMENTS=true
```

**Follow-ups:** magic-link guest reviewers (C6); viewport screenshots (C7); BYO preview URL review hub.

---

### Runbook marketplace (`surface.marketplace`)

**Route:** `/marketplace` · **Flag:** `surface.marketplace`

Curated **project runbooks** import into a workspace (project) Operations tab via `RECIPE_WORKSPACE_RUNBOOK`:

| Destination | Recipe type |
|-------------|-------------|
| Org webserver templates | `webserver_template` |
| Server saved commands / deploy | `server_recipe`, `deploy_command` |
| **Project runbooks** | `workspace_runbook` |

**Seed slugs:** `runbook-deploy-rollback`, `runbook-database-restore`, `runbook-edge-origin-failover`, `runbook-incident-first-15`, `runbook-ssl-renewal-panic`

**Code:**

- [`app/Services/Marketplace/MarketplaceImportService.php`](../app/Services/Marketplace/MarketplaceImportService.php) — `importWorkspaceRunbook()`
- [`app/Livewire/Marketplace/Index.php`](../app/Livewire/Marketplace/Index.php)

**Enable:**

```env
FEATURE_SURFACE_MARKETPLACE=true
```

---

### Ephemeral deploy credentials (`workspace.ephemeral_credentials`)

**Site setting:** Deploy → **Ephemeral deploy credentials** (BYO VM sites only)

Per-deploy ed25519 SSH key lifecycle:

| Phase | Behavior |
|-------|----------|
| Provision | Generate keypair, create `ServerAuthorizedKey`, sync to server |
| Deploy | SSH uses ephemeral private key via scoped context override |
| Revoke | Delete authorized key row, re-sync, mark credential revoked (always, even on failure) |

**Code:**

- [`app/Services/Deploy/EphemeralDeployCredentialManager.php`](../app/Services/Deploy/EphemeralDeployCredentialManager.php)
- [`app/Models/SiteDeploymentEphemeralCredential.php`](../app/Models/SiteDeploymentEphemeralCredential.php)
- [`app/Jobs/RunSiteDeploymentJob.php`](../app/Jobs/RunSiteDeploymentJob.php) — provision / revoke wrapper
- Site meta: `deploy.ephemeral_credentials` (boolean opt-in per site)

**Enable:**

```env
FEATURE_WORKSPACE_EPHEMERAL_CREDENTIALS=true
```

**Audit actions:** `site.deploy.ephemeral_credential_provisioned`, `site.deploy.ephemeral_credential_revoked` (fingerprint on deploy audit metadata)

---

## Tier B backlog (not started)

| Idea | Suggested branch | Notes |
|------|------------------|-------|
| — | — | Tier B differentiators above are implemented on this branch |

---

## Related

- [`DIFFERENTIATION_IDEAS.md`](DIFFERENTIATION_IDEAS.md) — full idea backlog + scoring
- [`edge-roadmap-next.md`](edge-roadmap-next.md) — Edge Waves E+
- [`CreateHybridEdgeStack`](../app/Actions/Edge/CreateHybridEdgeStack.php) — hybrid provisioning action
