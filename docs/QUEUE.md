---
title: "Queues"
slug: queue
category: "Services"
order: 310
description: "A managed, SQS-compatible job queue. Point Laravel at it instead of provisioning Redis — free for Serverless sites, priced per namespace elsewhere."
group: queue
---

# Queues

**dply Queue** is a hosted job queue for Laravel apps. It speaks the SQS wire protocol, so Laravel's built-in `sqs` driver talks to it unmodified — there is no package to install and no Redis to provision.

Find it under **Services → Queues**.

## Why it exists

A serverless function has no long-running process, so dply drains queues by holding several `queue:work` invocations open at once. That only works if every concurrent invocation can see the same job store — and the two defaults cannot. `sync` never enqueues anything at all, and `database` on SQLite writes to a per-container file, so each drain sees a different database and jobs vanish.

Before dply Queue, the only fix was "go provision a managed Redis" — a paid dependency standing between a new Serverless app and a working queue. Now the store is hosted for you, and Serverless sites get one automatically at no charge.

## Namespaces

A **namespace** is one queue endpoint: its own URL, its own credentials, its own capacity. Any Laravel app can hold one, whether or not dply deploys it.

Sites dply deploys on Serverless get a namespace created and wired automatically on deploy. You only create one by hand for an app hosted elsewhere, or to isolate one workload from another.

## Pricing

**A namespace serving a dply Serverless site is free.** Not discounted — it carries no line at all. That is the whole point: your queue should work on the first deploy without a purchase decision.

Every other namespace is priced by **capacity tier**:

| Tier | Max queue depth | Rate limit | Price |
|---|---|---|---|
| Standard | 100,000 jobs | 600 req/min | $9/mo |
| Pro | 500,000 jobs | 1,200 req/min | $29/mo |

You are billed **per namespace, not per job**. Pushing ten jobs or ten million costs the same — the tier's rate limit is the ceiling, not your invoice.

Billability follows the site a namespace currently serves, not how it was created. If a site moves off Serverless, its queue moves onto its tier price — and dply notifies you when that happens, before it reaches a bill.

## Connecting an app dply deploys

Nothing to do. The deploy writes `QUEUE_CONNECTION=dply` and the `DPLY_QUEUE_*` credentials into the environment, and the injected handler registers the connection before your app boots.

## Connecting an app hosted elsewhere

Two steps. First, add the credentials from the namespace page to your `.env`:

```
QUEUE_CONNECTION=dply
DPLY_QUEUE_URL=https://…/api/queue/v1/{namespace}
DPLY_QUEUE_KEY=...
DPLY_QUEUE_SECRET=...
```

Second — and this part is easy to miss — add a `dply` connection to `config/queue.php`:

```php
'dply' => [
    'driver' => 'sqs',
    'key' => env('DPLY_QUEUE_KEY'),
    'secret' => env('DPLY_QUEUE_SECRET'),
    'prefix' => env('DPLY_QUEUE_URL'),
    'endpoint' => env('DPLY_QUEUE_URL'),
    'queue' => 'default',
    'region' => 'us-east-1',
],
```

> **Do not point your existing `sqs` connection at dply.** Laravel's stock `sqs` block has no `endpoint` key, so without one the AWS SDK routes to real AWS no matter what prefix you set. It also reads `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` — repurposing it would hand your S3 credentials to the queue. A separate connection avoids both.

`region` is required by the AWS SDK's signing code and is not otherwise meaningful.

Then run a worker as usual:

```
php artisan queue:work dply
```

## Credentials

Each namespace can hold **two live credentials at once**. This is deliberate: a `.env` change only reaches your app on its next deploy, so rotation needs an overlap.

To rotate: mint a new credential, deploy your app with it, then revoke the old one.

Secrets are shown once, at mint time. dply stores them encrypted rather than hashed, because SigV4 signing requires the server to recompute the signature from the shared secret — there is nothing a hash could be compared against. They are scoped to a single namespace for exactly that reason.

## Capacity and limits

- **Queue depth** — pushes are rejected once the queue holds more jobs than the tier allows. Drain it or move up a tier.
- **Rate limit** — per namespace, per minute, across all operations.
- **Payload size** — 256 KB per message.

Depth is fixed to the tier at the moment you bought it, so re-pricing a tier later never shrinks a running queue underneath you.

## What works, and what does not

Laravel's queue API is broader than any single driver supports. On dply Queue:

| Feature | Status |
|---|---|
| Dispatch, delay, retry, timeouts | Works |
| `ShouldBeUnique`, `WithoutOverlapping`, `RateLimited` | Needs a shared **cache** — these are cache-backed, not queue-backed |
| Job batches | Needs your app's database (`job_batches`) |
| Failed jobs | Your app's database by default; see below |
| Horizon | **Not available** — Horizon is hard-wired to Redis |

Delays over 15 minutes are supported, unlike real SQS.

`retry_after` misconfiguration — the classic Laravel queue bug where a job's timeout exceeds the retry window and the same job runs twice — is **not representable** here. The lease is decided server-side from the job's own declared timeout, so the two cannot disagree.

### Failed jobs

Laravel writes failed jobs to your own application database by default. The **Failed jobs** tab on a namespace shows what dply has recorded, which for an externally-hosted app is nothing until you point your app at dply's failed-job store. An empty list there does not mean you have had no failures.

For sites dply deploys, dply is the failed-job store, and the tab shows real failures with retry and delete.

### Instead of Horizon

Horizon cannot run on a non-Redis driver. The namespace page covers what you would otherwise open Horizon for: current depth split by pending / reserved / delayed, jobs pushed per day, and failed jobs with one-click retry.

## Ordering

Jobs are claimed with `SKIP LOCKED`, which is not strictly FIFO under concurrent workers. Laravel users often expect the `database` driver's ordering; if your workload genuinely depends on strict ordering, it needs to be enforced in your job design rather than assumed from the queue.

## Deleting a namespace

Deleting discards any jobs still queued and immediately breaks apps using its credentials. Drain it first. The page warns you with the current depth before you confirm.
