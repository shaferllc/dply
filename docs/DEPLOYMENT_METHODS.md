# Deployment methods

dply models a deploy as **two independent axes**, so adding a method is picking a
cell in a matrix instead of writing a new monolith:

```
                 ── traffic cutover ──────────────────────────────
 release          instant     rolling      canary      maintenance   recreate
 placement      (flip once)  (node×node)  (subset→%)   (page up)     (stop→start)
 ───────────────────────────────────────────────────────────────────────────
 flat (in-place)    ✓ flat       –            –           ✓ maint        ✓ recreate
 atomic (symlink)   ✓ atomic     ✓ rolling    ✓ canary    ✓ maint        ✓ recreate
 blue-green (2 trees) ✓ b/g      ✓ rolling    ✓ canary    –             –
 image (container)  ✓ image      ✓ rolling    ✓ canary    ✓ maint        ✓ recreate
```

- **Placement** = how the new release lands on disk/host: `flat` (update the live
  checkout in place), `atomic` (build a fresh `releases/<ts>`, symlink-flip
  `current`), `blue_green` (two long-lived trees, build the idle one), `image`
  (build + run a container image per release).
- **Cutover** = how traffic moves to it: `instant` (flip once), `rolling`
  (node-by-node behind the LB, drain → deploy → health → re-add), `canary`
  (subset/weight → observe health+errors → promote or roll back), `maintenance`
  (raise the maintenance page, deploy, lower it — for exclusive/destructive
  migrations), `recreate` (stop the runtime, deploy, start — accepts downtime).

For v1 we expose the meaningful cells as a single **`deploy_method`** enum (a
named cell), and a resolver maps each method → `(placement, cutover, required
capabilities)`. `deploy_strategy` (`simple`/`atomic`) stays as the on-disk
placement primitive the existing engines already key on; `deploy_method` is
derived-compatible (`flat→simple`, everything else → `atomic`/its placement).

## Methods & the infra each reuses

| Method | Placement | Cutover | Reuses | Status |
|---|---|---|---|---|
| `flat` | flat | instant | SiteGitDeployer simple path | **built** |
| `atomic` | atomic | instant | AtomicSiteDeployer + AtomicDeployHealthChecker | **built** |
| `maintenance` | (host's) | maintenance | `global.maintenance_mode` Pennant flag | **build now** |
| `recreate` | (host's) | recreate | systemd unit stop/start (`dply-site-<id>`) | **build now** |
| `blue_green` | blue_green | instant | atomic releases (2 pinned trees) + health check | scaffold |
| `rolling` | atomic/image | rolling | WorkspaceLoadBalancers + worker pools + health check | scaffold |
| `canary` | atomic/image | canary | LB weighting + health check + Errors/Insights | scaffold |
| `image` | image | instant | EdgeBackend / container runtime (Docker on VM) | scaffold |

## Capability gating

A method only appears for a site whose infra supports it (`DeploymentMethod::
availableFor(Site)`):

- `rolling`, `canary` → site is load-balanced OR a worker pool (≥2 backends).
- `blue_green` → atomic-capable host with disk headroom for two trees.
- `image` → host has a container runtime; or the site is a Cloud/Edge site.
- `maintenance`, `recreate`, `flat`, `atomic` → any managed host.

Unsupported methods are hidden (not shown-then-errored), matching
[[feedback_cloud_seamless_surface]].

## Switching methods — convert on enable, migrate on disable (decided)

**Enable (flat → atomic)** runs immediately via `SiteAtomicLayoutRequester` +
`ConvertSiteToAtomicLayoutJob`. The strategy column stays `simple` until convert
finishes (workers first, then web). Confirm in the UI (or an explicit API flag).
Deploys are locked while converting. Per host: copy the live tree into
`releases/<ts>` when flat; repair-in-place when already atomic; archive leftover
root when hybrid. Seed `shared/.env` + `shared/storage` and attach them before
nginx/units are pointed at `current`.

**Disable (atomic → flat)** is armed in `meta['deploy_layout_migration']` +
`atomic_layout.status=disabling`. The column stays `atomic` until the next
deploy, which **forces the simple engine**, then `SiteDeployLayoutMigrator`
archives `releases/`/`current`/`shared` and the column flips to `simple`.

Cleanup is **archive-then-prune** (`<root>/.dply-layout-archive-<ts>/`) so a bad
migration is reversible; retention = keep last N archives.

## Phasing

1. **Foundation (now):** `deploy_method` enum + resolver + capability gating;
   migration column; `SiteDeployLayoutMigrator` + arming on switch; wire `flat`
   and `atomic` into the new model (no behavior change).
2. **Cheap wins (now):** `maintenance` and `recreate` cutovers (wrap an existing
   deploy with maintenance-flag / stop-start).
3. **Blue-green:** two pinned release trees + instant flip + one-flip rollback.
4. **Rolling / canary:** LB/pool orchestration — drain, deploy, health-gate,
   re-add / weight-shift, auto-rollback on health/error regression.
5. **Image:** Docker-on-VM build+run; converge with the Cloud/Edge image path.

Each phase ships behind a flag and leaves the others as registered-but-NYI
methods (capability-gated, so they never surface half-built).
