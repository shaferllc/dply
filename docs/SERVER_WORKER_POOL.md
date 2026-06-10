---
title: "Worker pool"
slug: server-worker-pool
category: "Servers"
order: 40
description: "Scaling background processing by cloning a worker server into a pool of a primary and replicas, covering convergence, draining, and Horizon tuning."
group: servers
---

# Worker pool

Scale background processing by cloning a worker server into a **pool**. The pool keeps one **primary** (which owns the scheduler) and adds queue-worker **replicas** that join the same queues.

## Requirements

Only a server with the worker role (the `queue_worker` profile) can start a pool. Create the pool from the primary, then scale to as many members as you need.

## How members converge

Each new member is provisioned, replays the primary's sites, deploys, and then joins the pool as an active worker. A **reconciler** advances stuck members and re-checks pending deploys — re-run it if a member is slow to converge.

## Scaling down

Members are **drained** before removal so in-flight jobs finish first, then destroyed. The primary is never dropped while replicas still exist.

## Worker tuning

Queue concurrency, balance strategy, memory, timeout, and retries are driven by the `HORIZON_*` settings dply writes to each box; sensible defaults are applied when the pool is created.

## Related sections

- **Daemons** — the worker/Horizon process on a single server
- **Schedule** — the scheduler the primary owns
- **Health** — watch member readiness
