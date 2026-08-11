# Runbook — split the dply Queue job store onto its own database

**Status:** not yet done in production (verified 2026-08-11).
**Urgency:** do it before the first customer enqueues anything. Cost rises sharply after that.

## Why

`config/database.php` defines a `dply_queue` connection, but every key falls
through to the primary `DB_*` when the `DPLY_QUEUE_DB_*` overrides are unset —
and they are unset, in this checkout and in the `dply` site's synced env
(`DB_HOST=10.0.0.3`, `DB_DATABASE=dply`, no queue overrides).

So customer job pushes and claims currently land in the same Postgres database
as sites, servers, billing and the dashboard.

The module is written as though this is not true:

- `Queues::render()` degrades depth to "unknown" on the premise that "the store
  is a separate database and can be unreachable while the control plane is fine".
- `dply_queue_jobs` carries `autovacuum_vacuum_scale_factor = 0.01`,
  `autovacuum_vacuum_cost_delay = 0` and `fillfactor = 80`, tuning written for a
  host that is not also serving the dashboard.

`dply_queue_jobs` is high-churn by design: every claim rewrites the indexed
`visible_at`, so no update can be HOT — each writes a new heap tuple **and** a
new index entry, and each ack deletes both. On a shared instance that vacuum and
WAL pressure is paid by the control plane.

Check the current state at any time:

```
php artisan dply:queue:doctor
```

The `store_isolation` check is the one that matters here.

## Decide first: separate database, or separate instance?

| | Separate **database**, same instance | Separate **instance** |
|---|---|---|
| Isolates logical schema | yes | yes |
| Isolates connection slots | no | yes |
| Isolates WAL / autovacuum workers / shared buffers / IO | **no** | yes |
| Effort | minutes | provision + tune a box |
| Reversible | trivially | trivially, before traffic |

A separate database on the same instance does **not** solve the failure mode
above — it only makes the later move to a separate instance a one-line host
change. Do it as a staging step, not as the destination.

## Steps

1. **Confirm the current state.**
   ```
   php artisan dply:queue:doctor --json | jq '.summary.store'
   ```
   Stop here if it already says "separate from the control plane".

2. **Confirm the store is empty.** This is what makes the move free.
   ```sql
   SELECT count(*) FROM dply_queue_jobs;
   SELECT count(*) FROM dply_queue_failed_jobs;
   SELECT count(*) FROM dply_queue_locks;
   ```
   If any are non-zero, this is no longer a config change — it is a data
   migration with a drain window, and needs its own plan.

3. **Create the database and a role that owns only it.**
   ```sql
   CREATE ROLE dply_queue LOGIN PASSWORD '<generated>';
   CREATE DATABASE dply_queue OWNER dply_queue;
   ```
   A dedicated role is the point: the control-plane credentials should not be
   able to read customer payloads, and vice versa.

4. **Set the env on the `dply` site** (Site → Environment, or
   `ServerEnvironment::mergeKeys`):
   ```
   DPLY_QUEUE_DB_HOST=10.0.0.3
   DPLY_QUEUE_DB_PORT=5432
   DPLY_QUEUE_DB_DATABASE=dply_queue
   DPLY_QUEUE_DB_USERNAME=dply_queue
   DPLY_QUEUE_DB_PASSWORD=<generated>
   ```
   Keep `DPLY_QUEUE_DB_SSLMODE` aligned with `DB_SSLMODE`.

5. **Migrate.** The three queue migrations declare
   `protected $connection = 'dply_queue'`, so a normal migrate creates them in
   the new database — including the `ALTER TABLE … SET (autovacuum…)` statement.
   ```
   php artisan config:clear && php artisan migrate --force
   ```

6. **Verify.**
   ```
   php artisan dply:queue:doctor
   ```
   `store_isolation`, `schema` and `table_health` must all be green. The last
   one confirms the per-table autovacuum tuning landed in the new database —
   it is easy to lose in a hand-built table and it is not optional.

7. **Drop the old tables** from the control-plane database, once the doctor is
   green and a queue round-trip works end to end (push via the SQS endpoint,
   drain with a worker):
   ```sql
   DROP TABLE dply_queue_jobs, dply_queue_failed_jobs, dply_queue_locks;
   ```
   Leaving them is worse than untidy: a future misconfiguration would silently
   start writing to a stale copy that looks plausible.

## Rollback

Unset the five `DPLY_QUEUE_DB_*` vars and `config:clear`. The connection falls
back to the primary database, where the tables still exist until step 7. That is
the whole rollback — which is exactly why step 7 waits for a verified round trip.

## After this

The store split is step 1 of the queue infrastructure sequence:

1. **This runbook** — isolate the store.
2. A dedicated PHP-FPM pool for `location /api/queue/` on `dply-app`, so a
   customer's long-poll cannot exhaust the pool serving the dashboard.
   `longPoll()` pins a worker for up to 5s.
3. Write the ADR for the pop pool. `config/queue_service.php` already refers to
   "the dedicated Octane pop pool (see the ADR)" — **no such ADR exists**.
4. Only then a `queue_edge` server role + load balancer, if measurements justify
   the hardware.
