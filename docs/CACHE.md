---
title: "Caches"
slug: cache
category: "Services"
order: 320
description: "A shared cache your apps can coordinate through. Laravel's built-in dynamodb driver talks to it unmodified — free, no Redis to provision."
group: cache
---

# Caches

**dply Cache** is a hosted cache for Laravel apps. It speaks the DynamoDB wire protocol, so Laravel's built-in `dynamodb` cache store talks to it unmodified — there is no package to install and no Redis to provision.

Find it under **Services → Caches**.

## Why it exists

Not to make your pages faster. To make your locks work.

Laravel implements `ShouldBeUnique`, `WithoutOverlapping`, and `RateLimited` against the **cache**, not the queue. On a serverless function — and on any container site running more than one replica — the default cache store is per-container. Each instance gets its own, so every one of those three silently does nothing while appearing to work: two containers both "acquire" the same lock, and a job you marked unique runs twice.

That is a genuinely nasty class of bug, because nothing fails. There is no error to find. The job just runs more than once, occasionally, under load.

A shared cache is the fix, and until now the only way to get one was to go and buy a managed Redis.

## What it is good at, and what it isn't

Each cache operation is one HTTPS round trip — roughly **10–40ms**, against about **0.5ms** for a Redis on the same network.

That trade is deliberate. For locks, rate-limit counters, and cross-container coordination — small values, read a handful of times per request — 30ms is irrelevant next to the fact that the alternative *does not work at all*. For caching rendered page fragments in a loop, it is the wrong tool, and a dedicated cache is both larger and far faster.

Rules of thumb:

- **Do** use it for `Cache::lock()`, `ShouldBeUnique`, `WithoutOverlapping`, `RateLimited`, and any counter several containers must agree on.
- **Do** use `Cache::many()` / `Cache::putMany()` for bulk reads and writes — N keys cost one round trip instead of N.
- **Don't** put `Cache::get()` inside a loop. Twenty reads is twenty round trips.

## What works

| | Shared cache |
|---|---|
| `Cache::get()` / `put()` / `forget()` | Yes |
| `Cache::increment()` / `decrement()` | Yes, atomic |
| `Cache::lock()` | Yes — real mutexes across containers |
| `Cache::many()` / `putMany()` | Yes — one round trip for N keys |
| `Cache::tags()` | **Throws.** The `dynamodb` store is not taggable. |
| `Cache::flush()` | **Throws.** Use the Flush button in the dashboard. |

The last two are worth reading twice, because they fail at runtime rather than at deploy time. `Cache::tags(['x'])->get('y')` raises `BadMethodCallException` on this driver. If your app uses tagged caching, it needs a dedicated cache.

`Cache::flush()` throws because DynamoDB cannot truncate a table. dply owns the store, though, so the **dashboard can do what the driver can't** — there is a Flush button on the cache page.

## Storage and the quota

A cache is free, and bounded by **storage** rather than by requests. The default ceiling is **64 MiB**.

That is a great deal of room for what the cache is for — a lock is a few dozen bytes — and deliberately not much room for caching page output.

Two things to know about how the ceiling behaves:

- **At quota, writes are refused.** They are not evicted to make room. `Cache::put()` will throw rather than silently no-op, which is the honest failure: a cache that quietly drops what you asked it to store is worse than one that tells you.
- **Expiry is TTL-only.** Keys go when their TTL passes, reclaimed on a schedule. Nothing is evicted early, so unlike Redis there is no LRU behaviour to rely on. `Cache::forever()` is clamped to 30 days.

If you are hitting the ceiling, that is usually the product telling you something true: you are using it as a performance cache, and a dedicated cache will serve you better.

## Attaching it to a site

On a cache's page, pick a site and attach. dply writes the environment into the site and mints that site its own credential:

```
CACHE_STORE=dynamodb
DYNAMODB_ENDPOINT=https://…/api/cache/v1
DYNAMODB_CACHE_TABLE=<the cache id>
AWS_DEFAULT_REGION=us-east-1
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
```

**Redeploy the site** for it to take effect — an environment change only reaches a running app on its next deploy.

Detaching removes exactly those keys and revokes that site's credential.

A site holds one cache. Attaching a second swaps the first out rather than stacking, so you never end up with two half-configured endpoints.

## Wiring an app dply doesn't deploy

Any Laravel app can use a cache, whether or not dply hosts it. Mint a credential on the cache page and paste the same six variables. Nothing else changes — it is the framework's own driver.

Note that `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are the *same* two variables Laravel's `sqs` queue store reads. If you use dply Cache **and** dply Queue in one app, they share one key pair, and dply grants that key access to both. That is why credentials are minted per app rather than per resource.

## Secrets

A credential's secret is shown **once**, when it is minted. dply stores a hash, so it genuinely cannot be shown again — losing it means minting a new credential, not recovering the old one.

Revoking takes effect immediately. Any app still presenting a revoked key starts failing every cache call, including its locks.

## Dedicated caches

If you need speed rather than coordination — or tagged caching, which the shared tier cannot do — create a **dedicated cache** instead. It is a real Redis cluster: roughly 40× faster, as large as you size it, and `Cache::tags()` and `Cache::flush()` both work.

Create one from the same **New cache** button and pick the Dedicated tier. It takes a few minutes to provision, and the cache page shows the cluster's status while it does.

Resizing, backups, and deleting the cluster live on the cluster's own page under **Cloud → Databases**, linked from the cache. There is one control surface for it rather than two.

Managed Redis clusters you created before dply Cache existed still work exactly as they did — they now also appear under **Services → Caches** so they are manageable in one place.

## Pricing

The shared cache is **free**. There is no paid tier of it and no per-request charge.

A dedicated cache is billed like any other managed database, on top of your plan.

Moving a site from shared to dedicated changes `CACHE_STORE` from `dynamodb` to `redis`, so it takes effect on the next deploy rather than instantly. Detaching removes every variable from both tiers, so a swap never leaves half a configuration behind.
