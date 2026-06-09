# Worker Pools — Clone & Scale Worker Servers

**Status:** Phase 1 + Phase 2 implemented (incl. backend exposure, cross-provider, autoscaling, primary-health alerts, cost preview, tests)
**Author:** Claude Code
**Date:** 2026-06-03
**Branch:** preflight/worker-pools
**Related:** Design resolved via `/grill-me` interview (18 decisions). Builds on the existing worker-host concept (`server_role=worker` / `install_profile=queue_worker`) and the worker-mode site lockdown.

---

## Implementation Status (2026-06-03)

**Built & verified (compiles, migration applied, route/view/DI resolve):**
- Data model: `worker_pools` table + `servers.worker_pool_id`/`pool_role` + Postgres partial-unique index (one primary per pool). `App\Models\WorkerPool` + `WorkerPoolFactory`; `Server` wiring.
- Same-region (Phase 1): `WorkerPoolManager` (create/scale/promote/remove), `WorkerCloneProvisioner` (clone via provider job, joins source private network), `WorkerWorkloadReplayer` (replicate sites/processes/bindings/env + replica transform), `ReconcileWorkerPoolJob` (re-entrant converge **both directions** — scales up by provisioning replicas and scales down by draining the newest non-primary members when `desired_count` drops, so the scale box *and* the autoscaler both work), `DrainAndDestroyWorkerJob` (graceful drain → `DeleteServerAction`). Livewire `WorkspaceWorkerPool` + blade; nav gated worker-role-only via new `only_server_roles` helper filter.
- Cross-region core (Phase 2): `PoolEnvTransformer` (rewrite private→public hosts + build exposure plan), cross-region placement in the provisioner (skips private network, marks `cross_region`), `addCrossRegionReplica`, and a UI exposure-plan banner + cross-region add form with a secret-replication ack.
- **Release pinning:** scaled-in clones deploy the source site's **last successful deployment commit** (`git_branch = <sha>`, `meta.git_ref_kind = 'commit'`, `meta.pinned_release`), falling back to the source's ref when no success is recorded — so a new worker never runs ahead of the pool.
- **Deploy ordering (reconciler-owned, readiness-gated):** the replayer provisions each replicated site and records it in `meta.pool.pending_deploys`; the reconciler fires the first deploy per site only once it reports `meta.provisioning.state === 'ready'`. Member lifecycle: `PROVISIONING → REPLAYING → DEPLOYING → ACTIVE`. Replaces the earlier fixed-delay best-effort (which raced `ProvisionSiteJob`'s self-polling).

**Backend exposure (now automated, operator-confirmed):** `WorkerPoolExposureApplier` + `ApplyWorkerPoolExposureJob` open each cross-region backend using the multi-IP-safe pattern — DB bound password-gated (`pg_hba` `0.0.0.0/0` scram via `DatabaseEngineInstallScripts`) with one `ServerFirewallRule` per worker `/32` (tagged `worker-pool:{id}`); Redis via `CacheServiceNetworkExposure::expose` + per-`/32` rules. Redis password is never auto-rotated (warns instead, to avoid desyncing workers' env). Auto-triggered when a cross-region member goes active (the operator already confirmed by adding it) and re-runnable via a UI button; grants are pruned on scale-down (`pruneForMember`).

**Cross-provider:** the clone provisioner accepts a provider + credential override and dispatches the matching `Provision*Job` (Hetzner/DO/Linode/Vultr/Scaleway/UpCloud/EquinixMetal/FlyIo/AWS/GCP/Azure/Oracle); a different provider drops the source OS image/DO opts and uses BYO hosting. UI has provider + credential selectors and **region/size dropdowns** populated from `ResolveServerCreateCatalog` (best-effort; free-text fallback when a provider API call fails).

**Autoscaling:** `dply:worker-pools:autoscale` (every 5 min) → `AutoscaleWorkerPoolJob` reads queue backlog over SSH on the primary (`php artisan queue:size`) and sets `desired_count` = clamp(backlog ÷ jobs-per-worker, min, max) with a cooldown. Config + toggle in the UI (`pool.meta.autoscale`).

**Primary-health alert (manual failover, per decision):** `dply:worker-pools:primary-health` (every 5 min) alerts org owners/admins via `NotificationPublisher` when a pool primary is unhealthy >10 min (cooldown 60 min). Promotion stays manual (one-click in UI) + delete-guard; no auto-promotion (split-brain).

**Cost preview:** dollar estimate from the primary's `billingTier()->priceCents()` shown on the scale control.

**Tests:** `tests/Feature/WorkerPoolTest.php` (Pest) covers pool creation, non-worker rejection, scale validation + reconcile dispatch, promote single-primary invariant, primary-removal guard + drain dispatch, and the env-transformer rewrite/exposure-plan (and no-op) paths. Written, not run (per the project's manual-test preference).

---

## Overview

Let an operator **clone an existing worker server** and **scale worker capacity declaratively** — "I want N workers" — so background/queue throughput can be grown (and shrunk) with minimal effort. Clones replay the source's entire workload (sites, worker processes, env, bindings) onto freshly-provisioned boxes that attach to the **same shared queue/database** the source is draining, so each clone adds real throughput rather than running an independent copy of the app.

The unit of management is a new **Worker Pool**: the source plus its clones, with exactly one **primary** (owner of the scheduler, cron, and migrations) and the rest as **replicas** (queue workers only).

## Background/Problem Statement

Today a worker host (`server_role=worker`) runs queue workers from deployed code but cannot be horizontally scaled in any first-class way:

- The only "clone" feature is `app/Actions/Servers/CloneServerOnDigitalOcean.php` + `app/Jobs/CloneServerOnDigitalOceanJob.php`, which is **DigitalOcean-only** (snapshots a droplet) and **deliberately copies only the bare box** — no sites, processes, env, or bindings. It cannot scale a Hetzner worker, and a bare clone runs nothing.
- There is **no server-level grouping** (the `SiteDeploySyncGroup` is sites-only), so there is nowhere to express "these N servers are one worker fleet," no single-primary invariant, and no scale action.
- Scaling workers correctly requires solving several hazards the platform does not address today: clones must share the *same* queue/DB (verbatim or rewritten env), must **not** double-run the scheduler/cron/migrations (singleton work), must stay on the **same code release**, and — when placed in another region/provider — must reach private backends that are currently private-only.

The core problem: **grow worker throughput safely and easily, without duplicating singleton work or splitting the queue.**

## Goals

- Clone a worker server on **any provider** (not just DigitalOcean) by re-provisioning and replaying its workload.
- Group a source worker and its clones into a **Worker Pool** with a single enforced **primary**.
- Provide **declarative scale-to-N** (manual desired count in v1) with safe scale-up and scale-down.
- Ensure every pool member runs the **same code release** and that **migrations/singleton work run exactly once**.
- Support clones in the **same region** (private network, verbatim env) and in a **different region/provider** (public-endpoint connectivity with host rewriting + firewall allowlisting).
- Provide **cost visibility and a hard cap**, **secret-replication consent**, and **best-effort, non-destructive reconciliation**.

## Non-Goals

Explicitly deferred (out of v1):

- **WireGuard / overlay mesh** for cross-region connectivity (considered and dropped in favor of public endpoints + TLS + allowlist).
- **Autoscaling** on metrics (queue depth / CPU) and **scheduled scaling**. The reconciler is built so a future autoscaler can simply write the desired count.
- **Single-artifact atomic promote** across members (we fan out deploys instead).
- **Automatic primary failover** (split-brain risk). v1 is manual promotion with alerting + a delete guard.
- Disk-snapshot cloning as the primary mechanism (legacy DO snapshot clone remains as a special case but is superseded by replay).

## Technical Dependencies

- Laravel 13.x / Livewire (existing app stack).
- Existing server provisioning: `app/Actions/Servers/StoreServerFromCreateForm.php`, `app/Livewire/Forms/ServerCreateForm.php`, per-provider `Provision*ServerJob` classes, `config/server_provision_options.php` (the `queue_worker` install profile, `worker` server role).
- Existing workload-replay machinery (from imports): `app/Services/Imports/Handlers/RecreateDaemonsHandler.php`, `app/Services/Imports/Handlers/RecreateSchedulerHandler.php`.
- Existing env + binding plumbing: `app/Services/Deploy/SiteBindingManager.php` (`attachRedis`, `effectiveServiceHost`, `reachableServerIds`, `sharePrivateNetwork`), `app/Services/Sites/SiteEnvPusher.php`, `app/Jobs/PushSiteEnvJob.php`, `app/Models/SiteBinding.php`.
- Existing deploy fan-out: `app/Services/Sites/SiteDeploySyncCoordinator.php`, `app/Services/Sites/SiteDeploySyncGroupManager.php`, `app/Models/SiteDeploySyncGroup.php`.
- Existing scheduler/cron generation: `app/Services/Servers/ServerCronSynchronizer.php` (`buildLaravelSchedulerBlock`).
- Existing private networking + firewall: `app/Models/PrivateNetwork.php`, `app/Jobs/ApplyFirewallJob.php`, `app/Models/ServerCacheService.php`, `app/Models/ServerDatabase.php`.
- Existing role/runtime semantics: `app/Models/Server.php` (`isWorkerHost()`, `meta.server_role`), `app/Support/DplyRuntime.php` (`DPLY_RUNTIME` / `DPLY_WORKER_ROLE`).

## Detailed Design

### Architecture Changes

Introduce a **Worker Pool** aggregate that owns a set of `Server` rows (one primary + N replicas) and a desired count. A **reconciler** job converges actual membership to desired by cloning (provision + replay + deploy) or draining+destroying. A **replay service** copies a source worker's sites/processes/env/bindings onto a target server, applying a **replica transform**. Cross-region members get an **env host-rewrite** + **backend-exposure/allowlist** step. Pool deploys **fan out** to all members with **primary-first, primary-only** singleton steps.

```
WorkerPool (desired_count, max_size, primary_server_id, backend refs)
  ├── Server (primary)    role=primary   ← owns scheduler/cron/migrations
  ├── Server (replica)    role=replica   ← queue workers only
  └── Server (replica)    role=replica
        ▲
        │ reconciler converges actual → desired
        │   scale-up:   provision → replay workload → (cross-region: rewrite+expose+allowlist) → deploy(pinned release)
        │   scale-down: pick newest non-primary → drain → destroy
```

### Implementation Approach

**1. Data model.** New `worker_pools` table + a `pool` membership and `role` on `servers`.

`worker_pools`:
| column | type | notes |
|---|---|---|
| `id` | ulid | pk |
| `organization_id` | ulid fk | scope |
| `name` | string | display |
| `source_server_id` | ulid fk nullable | provenance of the original |
| `primary_server_id` | ulid fk nullable | the single primary; enforced unique-per-pool |
| `desired_count` | int | target member count (incl. primary) |
| `max_size` | int | hard cap for scale-ups / future autoscaler |
| `status` | string | `steady`, `scaling`, `degraded` |
| `meta` | json | backend references, exposure state, last reconcile error summary |
| timestamps | | |

`servers` additions:
- `worker_pool_id` (ulid fk nullable)
- `pool_role` (enum: `primary` | `replica`, nullable)
- reuse/define `meta.cloned_from_server_id` (already stamped by the DO clone) for provenance.

Invariant: at most one `pool_role=primary` per `worker_pool_id` (enforced in the manager + a partial unique index).

**2. Replay service** (`app/Services/WorkerPools/WorkerWorkloadReplayer.php`, new). Given a source `Server` and a target `Server`:
- For each `Site` on the source: create the corresponding `Site` on the target (same type/runtime/document_root/repo/pipeline), copy `SiteProcess` rows (worker/scheduler/custom), copy bindings, and copy `.env`.
- Reuse the import handlers' approach (`RecreateDaemonsHandler`, `RecreateSchedulerHandler`) for process recreation rather than re-implementing.
- Apply the **replica transform** (see below) unless the target is being created as the primary.
- Env handling is delegated to the **env transform** step (verbatim vs rewrite).

**3. Replica transform.** For replica targets:
- `site.laravel_scheduler = false`; skip `SiteProcess` rows of type `scheduler` (do not create units for them).
- Rewrite any `DPLY_WORKER_ROLE=primary` → `replica` in the copied env.
- Only `worker` and `custom` processes get systemd units on replicas.
The primary keeps the full set.

**4. Placement.** Clone creation calls the existing `StoreServerFromCreateForm` flow with:
- **Same** `provider`, `provider_credential_id` as source (forced).
- **Same region** (default) → set `private_network_id` to the source's network so the box joins it.
- **Different region/provider** (allowed) → no private network; mark the member `meta.cross_region=true`, triggering the cross-region connectivity step.
- **Size** is operator-settable (default = source `size`).
- Clones always get **fresh SSH keys** (provisioning generates new key material; never copy source keys).

**5. Env transform.**
- **Same-region member:** copy `.env` **verbatim** (private IPs resolve over the shared private network).
- **Cross-region member:** copy `.env`, then **auto-detect every private-network service** referenced in it. For each key whose value is a private IP / pool-server host (e.g. `DB_HOST`, `REDIS_HOST`, `MEMCACHED_HOST`, and URL-form variants), rewrite it to that service's **public** address, and queue a **backend-exposure + allowlist** action on that service's server.
- Always **honor `site.meta.ignored_env_keys`** (never copy those keys).

**6. Backend exposure (cross-region only, confirm-gated).** When the first cross-region member is added and a referenced backend is private-only:
- Surface an explicit confirmation ("expose `<service>` on `<server>` to the internet over TLS for cross-region workers?").
- On confirm: enable a **public TLS listener** with auth required, **allowlist-only** (never plaintext, never `0.0.0.0`-open), via the existing firewall machinery (`ApplyFirewallJob` + provider cloud-firewall where applicable). Applies to **Postgres as well as Redis** — jobs hit the DB, not just the queue, so all referenced stateful services are in scope.
- Each new cross-region member's public IP is added to each referenced service's allowlist.

**7. Reconciler** (`app/Jobs/ReconcileWorkerPoolJob.php`, new; dispatched on desired-count change and re-queued until steady). Compares `desired_count`/`max_size` to current healthy members:
- **Scale-up:** for each missing member → provision (StoreServerFromCreateForm) → replay workload → (cross-region: env rewrite + exposure + allowlist) → **deploy pinned to the pool's current release** (the commit the pool is running, not branch tip).
- **Scale-down:** choose victim = **newest non-primary** member; **drain** (stop pulling new jobs; let in-flight finish via graceful `queue:work` stop / SIGTERM + max-drain timeout, then force) → **deprovision**.
- **Best-effort:** retry failed members with backoff; **never tear down healthy members**; a stuck member does not wedge the pool. Pool `status=degraded` with per-member errors surfaced when `healthy < desired`.

**8. Pool deploys.** Deploying the app fans out to **all members** via `SiteDeploySyncCoordinator`. Singleton/one-time steps (migrations + hooks flagged `primary-only`) run **only on the primary**, which deploys **first**; replicas then run a **worker-only pipeline** that skips those steps. This removes migration races and the new-code/old-schema window. Scale-up always deploys the pool's **current release**.

**9. Scheduler/cron correctness.** `ServerCronSynchronizer::buildLaravelSchedulerBlock` already emits `schedule:run` per site with `laravel_scheduler=true`. The replica transform sets `laravel_scheduler=false` on replicas, so only the primary emits the scheduler cron — no change needed beyond the transform. (`onOneServer` via shared cache remains a belt-and-suspenders safety but is not relied upon.)

**10. Failover (manual).** Alert when the primary is unhealthy; provide one-click **promote a replica** (which demotes the current primary, applies/removes the scheduler transform on both, and re-applies their pipelines/cron). **Block deleting or draining the primary** until another member is promoted.

### Code Structure

New:
- `app/Models/WorkerPool.php`
- `app/Services/WorkerPools/WorkerWorkloadReplayer.php` — replay sites/processes/env/bindings + replica transform.
- `app/Services/WorkerPools/WorkerPoolManager.php` — create pool, add/remove member, promote primary (single-primary invariant), cost preview, env transform orchestration.
- `app/Services/WorkerPools/PoolEnvTransformer.php` — verbatim vs cross-region host rewrite; private-service detection.
- `app/Jobs/ReconcileWorkerPoolJob.php` — converge actual → desired (scale-up/down).
- `app/Jobs/DrainAndDestroyWorkerJob.php` — graceful drain then deprovision.
- `app/Jobs/ExposeBackendForCrossRegionJob.php` — TLS listener + allowlist (confirm-gated).
- `app/Livewire/Servers/WorkerPool*.php` + Blade views — pool page (scale-to-N w/ cost preview + cap, member list w/ roles, promote, drain/remove, degraded status, confirm dialogs).
- DB migrations: `worker_pools` table; `worker_pool_id`, `pool_role` on `servers`; partial unique index for single primary.

Modified:
- `app/Models/Server.php` — `workerPool()` relation, `isPoolPrimary()`, role accessors.
- `app/Services/Servers/ServerCronSynchronizer.php` — no logic change expected (driven by `laravel_scheduler`), verify replica suppression.
- Deploy pipeline executor — honor a `primary_only` flag on steps/hooks and primary-first ordering for pooled deploys (extend `SiteDeploySyncCoordinator` usage).
- `app/Actions/Servers/StoreServerFromCreateForm.php` — accept a pool/role/clone context (pool id, role, cloned_from, network inheritance) so provisioning stamps membership.
- Server delete/drain paths — primary delete-guard.
- Legacy `CloneServerOnDigitalOcean` — keep, but route the new "clone" UX through the replay reconciler; document as superseded.

### API Changes

No public HTTP API. Internal Livewire actions on the Worker Pool page:
- `createPool(sourceServer, name)`
- `setDesiredCount(pool, n)` → cost preview → dispatch `ReconcileWorkerPoolJob` (rejects `n > max_size`).
- `promoteMember(pool, server)` (single-primary invariant).
- `removeMember(pool, server)` → `DrainAndDestroyWorkerJob` (blocked for primary).
- Cross-provider secret-replication confirm + backend-exposure confirm are gated dialogs feeding the reconciler.

### Data Model Changes

As in **Implementation Approach §1** — `worker_pools` table, `servers.worker_pool_id`, `servers.pool_role`, single-primary partial unique index, `meta.cloned_from_server_id` provenance.

## User Experience

1. From a worker server, operator clicks **"Create worker pool"** (the server becomes the pool's primary/source).
2. On the pool page, operator sets **desired count** (and optional **max size**). A **cost preview** shows the monthly delta before applying; counts above `max_size` are rejected.
3. Operator may add members in a **different region/provider**; the first such add triggers a **secret-replication confirm** ("secrets will be replicated to `<provider/region>`") and, if backends are private-only, a **backend-exposure confirm**.
4. The reconciler provisions, replays, and deploys clones (pinned release); the member list shows roles and live status (`desired N / healthy M`, degraded with per-member errors).
5. Deploys to the app update all members together (primary first; migrations once).
6. Scaling down drains the newest replica before destroying it. The **primary cannot be removed** until another member is promoted; if the primary is unhealthy, an alert offers one-click promotion.

## Testing Strategy

### Unit Tests
- `WorkerPoolManager`: single-primary invariant (promote demotes prior primary); `max_size` rejection; cost-preview math.
- `PoolEnvTransformer`: verbatim for same-region; private-service detection + host rewrite for cross-region; `ignored_env_keys` always excluded; `DPLY_WORKER_ROLE` primary→replica rewrite.
- Replica transform: `laravel_scheduler=false`, scheduler processes skipped, only worker/custom units on replicas.
- `ServerCronSynchronizer`: replica emits no `schedule:run`; primary does.
  *Purpose: prove singleton work runs exactly once and that clones share (not split) the backend.*

### Integration Tests
- Reconciler scale-up: missing members → provision/replay/deploy with pinned release; best-effort retry; healthy members never destroyed on a sibling failure; `degraded` status when `healthy < desired`.
- Reconciler scale-down: victim = newest non-primary; drain-before-destroy ordering; primary never selected.
- Pooled deploy fan-out: migrations/`primary_only` hooks run only on primary, primary deploys first, replicas skip them.
- Cross-region add: env rewrite + exposure/allowlist queued; same-region add: verbatim, no exposure.
- Primary delete-guard blocks until promotion.
  *Purpose: prove safe convergence, version lockstep, and no migration race.*

### E2E Tests
- Create pool from a worker → scale to 3 (same region) → deploy → scale down to 1, asserting workers attach to the same queue and only one scheduler runs throughout.

**Test Documentation:** Each test includes a purpose comment explaining why it exists.

## Performance Considerations

- Reconciler is queued and idempotent; convergence is incremental (one member at a time with bounded concurrency) to avoid provider rate-limit bursts.
- More workers on a `database` queue means more polling load on Postgres; surface this and allow (future) Redis-queue migration. Cross-region adds DB/Redis latency — acceptable, operator-visible via region choice.
- Drain timeout bounds scale-down latency; force-stop after timeout prevents indefinite hangs.

## Security Considerations

- **Secret replication:** full env is required for a functioning worker; same-provider copies are silent (same trust boundary), the **first cross-provider copy requires explicit confirmation**, and `ignored_env_keys` are never copied.
- **Backend exposure** is the highest-risk surface: only on explicit confirm, **TLS + auth required, allowlist-only, never plaintext/open**, applied to all referenced stateful services (Postgres included). Allowlists are scoped to member public IPs and pruned when members are destroyed.
- **Fresh SSH keys** per clone; source identity (host keys, machine-id, primary role) never copied.
- **Single-primary invariant + delete-guard** prevent split-brain scheduling and accidental scheduler loss.

## Documentation

- New `docs/` page: "Worker Pools — cloning and scaling workers," covering primary/replica roles, same-region vs cross-region tradeoffs, backend exposure implications, and scale-down draining.
- Update the worker-host docs to point at pools as the scaling path.
- Note the legacy DO snapshot clone is superseded by replay.

## Implementation Phases

### Phase 1: MVP/Core Functionality (same-region only)
- `worker_pools` table + `servers.worker_pool_id`/`pool_role` + single-primary index.
- `WorkerPoolManager` (create, add/remove, promote, invariants) + `WorkerWorkloadReplayer` + replica transform.
- `ReconcileWorkerPoolJob` (scale-up/down, best-effort, drain-before-destroy) — **same region, verbatim env**.
- Pooled deploy fan-out with primary-first / `primary_only` migration handling; release pinning on scale-up.
- Pool Livewire page: scale-to-N, cost preview, `max_size`, member list/roles, promote, remove, degraded status.
- Primary delete-guard + unhealthy-primary alert.

### Phase 2: Cross-region / cross-provider
- `PoolEnvTransformer` private-service detection + public host rewrite.
- `ExposeBackendForCrossRegionJob` (TLS + auth + allowlist) + confirm dialogs.
- Cross-provider secret-replication confirm.
- Allowlist pruning on member destroy.

### Phase 3: Polish (deferred candidates)
- Autoscaler that writes `desired_count` from queue-depth/CPU (reconciler already converges).
- Optional WireGuard/overlay mesh as an alternative to public exposure.
- Single-artifact atomic promote.

## Open Questions

1. **Queue backend for cross-region:** keep `database` (requires exposing Postgres publicly) or require/encourage a Redis queue first? (Either way Postgres must be reachable because jobs use it — so exposure is unavoidable for cross-region; the question is whether to nudge a queue migration for efficiency.)
2. **Drain mechanism specifics:** preferred signal/flag to stop a remote `queue:work` cleanly (supervisor stop vs `--stop-when-empty` vs SIGTERM) and the default max-drain timeout.
3. **Release pinning source of truth:** where the "pool's current release" is recorded (leader's last successful deployment vs a pool-level release pointer).
4. **Promotion side-effects ordering:** exact sequence when promoting (apply scheduler to new primary, remove from old) to guarantee zero-overlap of scheduler ownership.

## References

- Resolved design decisions (this spec's source): `/grill-me` interview, 18 questions.
- Existing clone: `app/Actions/Servers/CloneServerOnDigitalOcean.php`, `app/Jobs/CloneServerOnDigitalOceanJob.php`.
- Provisioning: `app/Actions/Servers/StoreServerFromCreateForm.php`, `config/server_provision_options.php`.
- Replay machinery: `app/Services/Imports/Handlers/RecreateDaemonsHandler.php`, `RecreateSchedulerHandler.php`.
- Env/bindings: `app/Services/Deploy/SiteBindingManager.php`, `app/Services/Sites/SiteEnvPusher.php`, `app/Jobs/PushSiteEnvJob.php`.
- Deploy fan-out: `app/Services/Sites/SiteDeploySyncCoordinator.php`, `app/Services/Sites/SiteDeploySyncGroupManager.php`.
- Cron/scheduler: `app/Services/Servers/ServerCronSynchronizer.php`.
- Networking/firewall: `app/Models/PrivateNetwork.php`, `app/Jobs/ApplyFirewallJob.php`.
- Role/runtime: `app/Models/Server.php` (`isWorkerHost`), `app/Support/DplyRuntime.php`.
