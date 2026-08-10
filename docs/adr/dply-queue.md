# ADR: dply Queue — managed job queue

Status: accepted (2026-08-10)

## Context

A serverless function has no long-running process, so dply drains queues with
`ServerlessQueuePump` — several concurrent `queue:work` invocations held open at
once. That only works when the app's queue store is reachable from *every*
concurrent invocation. `sync` (and unset, which the injected handler defaults to
`sync`) never enqueues anything; `database` on SQLite writes to a per-container
`/tmp` file, so concurrent drains see different databases and jobs are lost.
`ServerlessQueueBackend` classifies both and the pump refuses to drain them, so
the failure is loud — but the only remedy dply can offer is "provision a managed
Redis". **Every serverless Laravel app needs a paid Redis before its queue works
at all**, which is an adoption barrier on the first run.

**dply Queue** removes the prerequisite by hosting the job store. The app points
its queue config at dply; apps dply deploys are wired automatically.

Historical note: `ServerlessQueueBackend::adoptRedisIfBroken()` was written to
repair a `sync`/SQLite backend by pointing it at a provisioned Redis. It is the
product's real entry point, and becomes "provision a dply Queue namespace".

Precedent: **Realtime** is the existing case of a customer's own app talking to
dply-hosted infrastructure. It shipped **no client SDK** — the data plane speaks
the Pusher wire protocol, so `laravel-echo` works unmodified and dply injects
`PUSHER_*` at deploy. dply Queue follows that play.

## Decision

1. **Wire protocol** = SQS-compatible first. Laravel's built-in `sqs` driver
   works unmodified, so there is no package to publish or version. A native
   `dply` driver follows later for what SQS cannot express (delays over 900s,
   the lock and failed-job providers).
2. **Tenancy unit** = `QueueNamespace`, an org-owned resource modelled on
   `RealtimeApp` — ULID doubling as the data-plane id, `provisioning/active/
   failed/paused`, tier limits enforced at the data plane. Not scoped to a Site;
   any Laravel app can hold one.
3. **Credentials** = a separate `QueueCredential` type using
   `hash('sha256', $plaintext)` + prefix, following `EdgeDeployHook`. **Not
   `ApiToken`.** Its bcrypt hash is salted, so a cache key cannot be derived
   from the stored row and revocation could only wait out a TTL; it also writes
   `last_used_at` per request, requires a `User`, and carries org-wide
   abilities. A 48-char CSPRNG secret has no brute-force surface, so the slow
   KDF buys nothing and costs the design. Two credentials may be live per
   namespace, because a `.env` only reaches the app on the next deploy.
4. **Visibility** = one `visible_at` column, not `available_at` + `reserved_at`.
   Laravel's `DatabaseQueue` expresses availability as a disjunction across two
   timestamps, which is not indexable as one range. Push, reserve, and release
   all write the single "earliest moment this row may be claimed", so an expired
   lease is indistinguishable from availability and **no sweeper is needed**.
5. **Fencing** = every `ack` / `release` / `fail` carries the `reservation_id`
   it was issued, matched server-side. Without it, a stalled worker's late ack
   deletes another worker's reservation and the job vanishes with no failure
   record. SQS's ReceiptHandle supplies this on the compatible path.
6. **Lease ownership** = the server. `retry_after` is client config in
   `DatabaseQueue`; once the store is remote, two deploys would disagree about
   expiry. The lease is decided at reserve and written to the row. All
   timestamps come from `now()` inside Postgres, never from PHP.
7. **Lease clamping** = `max(job_timeout + grace, requested_visibility)`, read
   from the job envelope, which carries `timeout`, `maxTries`, `uuid`,
   `batchId`, and `displayName` in plaintext. This makes Laravel's
   `retry_after > timeout` misconfiguration — the most common queue bug there
   is — **unrepresentable** on dply Queue.
8. **Storage** = a dedicated `dply_queue` Postgres connection, not the primary.
   Customer payloads are arbitrary PII and a control-plane blast radius;
   aggressive autovacuum on a churn table competes with the whole cluster; a
   runaway tenant backlog must not degrade dply. Same split as Logs. Namespaces,
   credentials, and usage rollups stay in the primary.
9. **Metering** = jobs pushed. Not API requests, which would bill customers for
   dply's own polling design and invert the incentive to optimise it. Counted
   with Redis `INCRBY` at push and flushed hourly — the Logs pattern of metering
   from the store after the fact cannot apply, because acked rows are deleted.
10. **Gating** = `surface.queue` in `config/features.php`; pricing dials,
    entitlements, and the kill switch in a new `config/queue_service.php`, per
    the layering rules at `config/features.php:11-26`.

## Boundaries

| Concern | Backed by | Owner |
|---|---|---|
| Job transport | `dply_queue_jobs` | dply Queue |
| Failed jobs | app DB by default | app, until the companion package |
| `WithoutOverlapping`, `ShouldBeUnique`, `RateLimited` | cache | app — **not** the queue |
| Batches | app DB (`job_batches`) | app |
| Horizon | hard-wired to `RedisQueue` | unavailable on a non-Redis driver |

## Consequences

- New tables: `dply_queue_namespaces`, `dply_queue_credentials`,
  `dply_queue_usage_daily` (primary); `dply_queue_jobs`,
  `dply_queue_failed_jobs` (`dply_queue` connection).
- `dply_queue_jobs` is a **churn** table, not the append-only shape of
  `function_invocations`. `visible_at` is indexed, so every reserve writes a new
  heap tuple and index entry — no HOT update. The migration must set
  `autovacuum_vacuum_scale_factor = 0.01`, `autovacuum_vacuum_cost_delay = 0`,
  `fillfactor = 80`. Volume is bounded by consumption and a `max_queue_depth`
  push rejection, not by pruning.
- Honest ceiling: low thousands of jobs/sec on a dedicated instance. The Redis
  store that follows is scoped as **Horizon compatibility** (a real
  RESP-protocol endpoint), not as a storage swap.
- `SKIP LOCKED` is not FIFO under concurrency; Laravel users expect the database
  driver to be. Must be documented.
- Three cache-backed queue features and the failed-job store remain broken on
  serverless after this ships. `ServerlessQueueBackend` and
  `dply:serverless:queue-doctor` must detect and report them, or the product
  recreates — one layer up, and more subtly — the silent-failure class that
  `ServerlessQueueBackend` exists to eliminate.
- Not routed under `/api/v1`: that group is `throttle:api` at 60/min. A new
  `/api/queue/v1` group with a `dply-queue` limiter keyed by namespace, sized
  from the entitlement.
