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

   **Amended 2026-08-10 (see `managed-services-tier.md`):** a namespace may
   still be site-less, but `site_id` is now **load-bearing for pricing**.
   `ServerlessQueueProvisioner` populates it, and billability is derived live
   from the attached Site's backend — free when it serves `dply_serverless`,
   billed otherwise. A site-less namespace is billable.
3. **Credentials** = a separate `QueueCredential` type, **not `ApiToken`**.
   Its bcrypt hash is salted, so a cache key cannot be derived from the stored
   row and revocation could only wait out a TTL; it also writes `last_used_at`
   per request, requires a `User`, and carries org-wide abilities. Two
   credentials may be live per namespace, because a `.env` only reaches the app
   on the next deploy.

   **Amended 2026-08-10 (during M3):** the secret is **encrypted at rest, not
   hashed**. SigV4 is an HMAC over a shared secret, so the server must be able
   to recompute it; a hash has nothing to compare against. This is the same
   tradeoff `RealtimeApp::app_secret` makes for Pusher, and it is forced by the
   protocol rather than chosen. `token_hash` is retained as the cache-key
   source, so the exact-eviction revocation property is unchanged, and
   `token_prefix` doubles as the public access key id. Cost, stated plainly: a
   database dump plus a leaked `APP_KEY` now exposes secrets, where a hash
   would have exposed nothing. That is inherent to any shared-secret signing
   scheme and is why these credentials are scoped to one namespace and one
   product.
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

    **Amended 2026-08-10 (see `managed-services-tier.md`): demoted to
    observational.** v1 prices a namespace by capacity tier, Realtime-shaped,
    so nothing is invoiced from these counters. They still exist and still
    flush hourly, but they feed the UI's throughput sparkline only — a few
    percent of drift under failure is acceptable, and the exactly-once
    obligations that come from a number landing on an invoice do not apply.
    `requests_per_minute` is what caps COGS.
10. **Gating** = `surface.queue` in `config/features.php`; pricing dials,
    entitlements, and the kill switch in a new `config/queue_service.php`, per
    the layering rules at `config/features.php:11-26`.

    **Amended 2026-08-10 (see `managed-services-tier.md`):**
    `queue_service.tiers` is added (capacity → monthly/yearly price, mirroring
    `config('realtime.tiers')`). The metered entitlement keys —
    `monthly_included_jobs`, `overage_per_million_jobs_cents`, `hard_cap_jobs`,
    and `billing.per_million_jobs_cents` — are **deleted**, not zeroed. The
    per-plan overlay shrinks to `available` and `max_namespaces`; depth and
    rate belong to the tier.

11. **Client wiring** = a dedicated `dply` queue *connection* using the `sqs`
    driver, registered at runtime by the injected handler. Not the app's
    existing `sqs` connection.

    Two findings force this. First, a stock Laravel `config/queue.php` has no
    `endpoint` key in its `sqs` block, and without one the AWS SDK routes to
    real AWS regardless of `SQS_PREFIX` — verified. Second, that block reads
    `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`, so pointing it at dply would
    hijack the credentials the app uses for S3 and everything else.

    For apps dply deploys, the handler already boots the framework, so it can
    define the connection from `DPLY_QUEUE_*` before the kernel runs — nothing
    for the customer to edit and no shared AWS credentials disturbed. An
    external app adds one line (`'endpoint' => env('AWS_ENDPOINT')`) or waits
    for the companion package. The "no package" claim survives; the "nothing
    but env vars" claim does not, and should not be made for external apps.

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
