# ADR: Managed queue workers — dply-owned worker fleets

Status: accepted (2026-08-20). Extends `dply-queue.md`; amends its
2026-08-10 pricing amendment (see Decision 6).

## Context

`dply-queue.md` shipped the *transport* half of a managed queue: an
SQS-compatible endpoint over a Postgres store, with namespaces, credentials,
depth, failed jobs and usage metering. It deliberately stopped there — jobs
land in `dply_queue_jobs` and something else has to drain them.

Today three things drain a dply queue, and none of them is a product:

| Drainer | What it is | Why it is not enough |
|---|---|---|
| `WorkerPool` | clones of the *customer's own* VM running `queue:work` | customer pays for and operates the compute; floor of one worker; autoscale reads backlog over SSH on a 300s cooldown |
| `ServerlessQueuePump` | bounded concurrent function invocations | only for sites deployed as DO Functions |
| the customer's own box | whatever they run | invisible to dply |

The competitive reference is Laravel Cloud's managed queues: workers dply owns
and scales, attached to the queue rather than to the customer's servers,
scaling to zero when idle and waking in under a second, billed per second.

## Decision

1. **A fleet is bound to a namespace + one queue name.** `dply_queue_fleets`
   holds one row per (namespace, queue). One fleet drains exactly one queue
   name — same constraint Laravel Cloud accepted, and for the same reason:
   the autoscaling signal is only meaningful per queue.

2. **Workers run on dply-owned compute, in containers.** The precedent is
   `EdgeBuildRunner`, which already runs customer code in Docker on dply's own
   machines. A worker is a container running the customer's app image with
   `queue:work` pointed at the namespace's SQS-compatible endpoint — the same
   endpoint an external app would use, so there is no privileged path into the
   store that only dply's own workers can take.

3. **The runtime is behind a contract.** `WorkerRuntime` is start / stop /
   observe. `FakeWorkerRuntime` backs the tests and local development;
   `DockerWorkerRuntime` is the real one. Host placement (which dply machine a
   container lands on) is the runtime's problem, not the autoscaler's — that
   is what keeps "which substrate" a reversible decision rather than a rewrite.

4. **Scaling is driven by measured work, not CPU.** dply owns the store, so it
   can read pending depth, in-flight count and real job durations directly.
   Desired workers = enough to drain the visible backlog inside
   `target_drain_seconds`, never fewer than the in-flight count (those jobs
   already hold a worker), clamped to [min, max]:

   ```
   desired = clamp(max(reserved, ceil(pending * avg_duration / target_drain)), min, max)
   ```

   Scale-up is immediate. Scale-down is damped by requiring N consecutive
   quiet ticks, because a worker torn down mid-lull is re-created seconds
   later and both transitions are billed.

5. **Zero is a real floor, and pushes wake the fleet.** A `flex` fleet may set
   `min_workers = 0`. Waiting for the next scheduler tick would make an idle
   queue's first job wait up to a minute, so the push path wakes the fleet
   directly — the mechanism `ServerlessQueuePump::wake()` already established.
   `pro` fleets hold a floor of at least one worker and never sleep.

6. **Billing is per-second workers plus per-operation queue ops.**
   This reverses the 2026-08-10 amendment in `dply-queue.md`, which deleted
   metered entitlements in favour of capacity tiers. The reversal is
   deliberate and is scoped to the *worker* product: capacity tiers price a
   queue you poll yourself, and cannot price compute dply runs on the
   customer's behalf — an idle fleet must cost nothing and a busy one must
   cost in proportion. Queue operations return to metering alongside it so the
   two halves of one invoice are measured the same way.

## Boundaries

| Concern | Owner |
|---|---|
| Job transport, leases, failed jobs | dply Queue (`dply-queue.md`) — unchanged |
| Deciding how many workers should run | `FleetAutoscaler` |
| Making that many workers exist | `WorkerRuntime` implementation |
| Which machine a worker lands on | the runtime, not the autoscaler |
| `WithoutOverlapping`, `ShouldBeUnique`, batches | still the app's cache/DB, per `dply-queue.md` |

## Consequences

- New tables `dply_queue_fleets`, `dply_queue_workers` in the primary
  database. Worker rows are the billing record, so they are retained after the
  container is gone and pruned only once rolled up.
- FIFO is **not** in this slice. `PostgresQueueStore` uses `SKIP LOCKED`,
  which `dply-queue.md` already documents as not-FIFO under concurrency;
  ordering guarantees are a store change, not a fleet flag.
- Per-operation metering is additive to `QueueUsageMeter`, which counts pushes
  only today. Until it lands, worker-seconds accrue on the worker rows and
  nothing is invoiced from them.
- A worker runs the customer's code on dply's machines. Multi-tenant isolation
  (per-container memory caps, no host network, read-only mounts, egress
  policy) is a hard requirement of the Docker runtime, not a later hardening
  pass.
