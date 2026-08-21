<?php

/*
|--------------------------------------------------------------------------
| dply Cache — the managed cache
|--------------------------------------------------------------------------
|
| Runtime dials for the managed cache product. The product SURFACE is gated by
| the Pennant flag `surface.cache` in config/features.php — per the layering
| rules documented there, Pennant answers "does this org get the product" and
| this file answers "how does it behave and what does it cost".
|
| See docs/adr/dply-cache.md.
|
| Note the deliberate distinction from Laravel's own config/cache.php: that one
| configures the cache dply ITSELF runs. This one configures the cache dply
| SELLS. They share nothing. (Same reason config/product/queue_service.php is
| not named queue.php.)
|
| Note also the distinction from the `cache.*` flags in config/features.php,
| which gate cache ENGINES offered for install on BYO servers (Valkey,
| Memcached, KeyDB). Unrelated to this product.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | Off means caches cannot be created and deploys will not wire one.
    | Mirrors `queue_service.enabled`.
    |
    */

    'enabled' => filter_var(env('DPLY_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Public endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL customers point DYNAMODB_ENDPOINT at. Built from
    | DPLY_PUBLIC_APP_URL when unset, for the same reason the queue's is:
    | APP_URL is typically a local *.test in development and a customer's app
    | could never reach it.
    |
    */

    'public_url' => env('DPLY_CACHE_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Region
    |--------------------------------------------------------------------------
    |
    | Injected as AWS_DEFAULT_REGION and echoed in the SigV4 credential scope.
    | It addresses nothing — there is one endpoint — but the SDK requires a
    | region to sign at all, so it must be a value both sides agree on.
    |
    */

    'region' => env('DPLY_CACHE_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | The shared tier's quota
    |--------------------------------------------------------------------------
    |
    | dply Cache is free (docs/adr/dply-cache.md, decision 7) and this is what
    | bounds it. The limiter is BYTES, not requests: a queue's cost is
    | throughput, a cache's cost is resident bytes.
    |
    | 64 MiB is deliberately generous for what the shared tier is FOR — locks,
    | rate-limit counters, and cross-container coordination are bytes, not
    | megabytes — and deliberately small enough that a page-caching workload
    | reaches it and moves to a dedicated cache, which is both faster for them
    | and where the revenue is.
    |
    | `max_item_bytes` protects the store from any single write, the way the
    | queue's `max_payload_bytes` does.
    |
    */

    'shared' => [
        'quota_bytes' => (int) env('DPLY_CACHE_QUOTA_BYTES', 64 * 1024 * 1024),
        'max_item_bytes' => (int) env('DPLY_CACHE_MAX_ITEM_BYTES', 262_144),

        /*
        | Nothing may live forever in a free store. A `Cache::forever()` maps
        | to a very distant expiry on the DynamoDB driver, so without a clamp
        | the quota would only ever be reclaimed by eviction that TTL-only
        | storage does not do.
        */
        'max_ttl_seconds' => (int) env('DPLY_CACHE_MAX_TTL_SECONDS', 60 * 60 * 24 * 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | TTL sweep
    |--------------------------------------------------------------------------
    |
    | Expired rows are ALWAYS filtered on read, so a lagging sweeper can never
    | surface a stale value — the sweep reclaims space, it does not enforce
    | correctness. Batched so one pass cannot hold a long transaction against
    | the store.
    |
    */

    'sweep' => [
        'batch_size' => (int) env('DPLY_CACHE_SWEEP_BATCH', 5_000),
        'max_batches' => (int) env('DPLY_CACHE_SWEEP_MAX_BATCHES', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | How many caches an org may hold. Note this does NOT copy the ambiguity in
    | queue_service.entitlements.plans, where `business` reads 0 and `free`
    | reads 1 with no stated sentinel convention. Here 0 means zero and null
    | means unlimited, said out loud.
    |
    */

    'entitlements' => [
        'defaults' => [
            'available' => true,
            'max_caches' => 1,
        ],

        'plans' => [
            'free' => ['max_caches' => 1],
            'starter' => ['max_caches' => 2],
            'pro' => ['max_caches' => 10],
            'business' => ['max_caches' => null],
        ],
    ],

];
