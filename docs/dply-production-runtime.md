# dply production runtime (web + worker split)

Split the control plane across dedicated VMs when a single box cannot keep up with queue + HTTP load.

## Topology

| Server | `DPLY_RUNTIME` | `DPLY_WORKER_ROLE` | Supervisor programs |
|--------|----------------|--------------------|---------------------|
| Web | `web` | — | Reverb (`deploy/supervisor/dply-web.conf`) |
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
HORIZON_MAX_PROCESSES=6

# Worker 2 (replica)
DPLY_RUNTIME=worker
DPLY_WORKER_ROLE=replica
HORIZON_NAME=dply-worker-2
HORIZON_MAX_PROCESSES=4
```

Required for split deploys:

- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis` on the primary worker (for `Schedule::onOneServer()`)

Horizon UI stays on the **web** host at `/horizon` and lists both masters when `HORIZON_NAME` differs per worker.

## Supervisor install

1. Set `DPLY_ROOT` in `/etc/supervisor/supervisord.conf` or the `[supervisord]` environment block to your release path (e.g. `/var/www/dply/current`).
2. Copy the matching file from `deploy/supervisor/` to `/etc/supervisor/conf.d/`.
3. `supervisorctl reread && supervisorctl update`

Web tier: nginx + php-fpm are **not** in these snippets — configure them separately.

## Deploy order

1. **Worker 1** — pull release → `php artisan migrate --force`
2. **Worker 2** — pull → `php artisan horizon:terminate` → restart Horizon
3. **Worker 1** — pull → `horizon:terminate` → restart Horizon + `schedule:work`
4. **Web** — pull → reload php-fpm → restart Reverb

## Health checks

- Supervisor auto-restart on each box
- `php artisan dply:runtime:check` (included in worker supervisor templates every 5 minutes)
- `php artisan dply:about` shows runtime mode and configuration warnings
- Horizon dashboard on web for queue wait times and both worker masters

## Postgres backups (self-hosted DB VM)

- Provider disk snapshots on a schedule
- Nightly `pg_dump` to off-box object storage

See also: [BYO local setup](BYO_LOCAL_SETUP.md) for single-machine queue/Horizon dev.
