# dply production runtime (web + worker split)

Split the control plane across dedicated VMs when a single box cannot keep up with queue + HTTP load.

## Topology

| Server | `DPLY_RUNTIME` | `DPLY_WORKER_ROLE` | Supervisor programs |
|--------|----------------|--------------------|---------------------|
| Web | `web` | — | none (realtime is the Cloudflare relay; see `deploy/supervisor/dply-web.conf`) |
| Worker 1 | `worker` | `primary` | Horizon, `schedule:work` (`dply-worker-primary.conf`) |
| Worker 2 | `worker` | `replica` | Horizon only (`dply-worker.conf`) |
| Redis | — | — | Dedicated (queues, cache, schedule mutex) |
| Postgres | — | — | Dedicated DB VM |

Local / single-box installs leave `DPLY_RUNTIME=all` (default).

## Shared `.env`

All app boxes share the same database and Redis URLs. Per-host overrides:

```dotenv
# Web
DPLY_RUNTIME=web

# Worker 1 (primary)
DPLY_RUNTIME=worker
DPLY_WORKER_ROLE=primary
HORIZON_NAME=dply-worker-1

# Worker 2 (replica)
DPLY_RUNTIME=worker
DPLY_WORKER_ROLE=replica
HORIZON_NAME=dply-worker-2
```

Queues and process counts are defined in `config/horizon.php` (`$heavyQueues` / `$fastQueues`):

| Supervisor | Queues | Purpose |
|------------|--------|---------|
| `supervisor-heavy` | `dply-provision`, `dply` | Edge builds, BYO deploys |
| `supervisor-fast` | `default`, `dply-control`, `dply-manage`, probes… | Notifications, insights, short jobs |

Required for split deploys:

- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis` on the primary worker (for `Schedule::onOneServer()`)

Horizon UI stays on the **web** host at `/horizon` and lists both masters when `HORIZON_NAME` differs per worker.

After deploy on each worker: `php artisan horizon:terminate` (self-deploy also runs `dply:self:sync-supervisor` first via `dply:self-horizon-restart`).

## Supervisor install (from `dply.yaml`)

Control-plane supervisor programs are declared in the repo root [`dply.yaml`](../dply.yaml):

1. **Phase 1 (templates)** — `supervisor.roles` maps `worker.primary` / `worker.replica` / `web` → files under `deploy/supervisor/`. On each worker deploy, `dply:self:sync-supervisor` merge-writes an owned file (`/etc/supervisor/conf.d/dply-platform.conf` by default), preserves any local-only `[program:…]` blocks in that file, and refuses to clobber the same program name living in a sibling conf (use `--adopt-collisions` once to migrate off a hand-copied `dply.conf`). It also patches `DPLY_ROOT` into `supervisord.conf` `[supervisord] environment=`.
2. **Phase 2 (BYO processes)** — the same `dply.yaml` `processes:` block (with `roles`, `oneshot`, `loop_seconds`) reconciles to `SiteProcess` rows and dispatches `ControlWorkerDaemonJob` → `WorkerDaemonBackend` → `dply-sv-*.conf`. When that path owns the box, set `DPLY_SELF_SUPERVISOR_TEMPLATES=false` (or `self_manage.supervisor.use_templates=false`) to stop Phase 1 template sync.

Manual one-shot:

```bash
php artisan dply:self:sync-supervisor --dry-run
php artisan dply:self:sync-supervisor --adopt-collisions   # first migration only
```

Web tier: nginx + php-fpm are **not** in these snippets — configure them separately.

## Deploy order

1. **Worker 1** — pull release → `php artisan migrate --force`
2. **Worker 2** — pull → `php artisan horizon:terminate` → restart Horizon
3. **Worker 1** — pull → `horizon:terminate` → restart Horizon + `schedule:work`
4. **Web** — pull → reload php-fpm (realtime is the Cloudflare relay — nothing to restart on-box)

## Health checks

- Supervisor auto-restart on each box
- `php artisan dply:runtime:check` (included in worker supervisor templates every 5 minutes)
- `php artisan dply:about` shows runtime mode and configuration warnings
- Horizon dashboard on web for queue wait times and both worker masters

## Postgres backups (self-hosted DB VM)

- Provider disk snapshots on a schedule
- Nightly `pg_dump` to off-box object storage

See also: [BYO local setup](BYO_LOCAL_SETUP.md) for single-machine queue/Horizon dev.
