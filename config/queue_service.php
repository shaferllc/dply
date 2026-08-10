<?php

/*
|--------------------------------------------------------------------------
| dply Queue — the managed job queue
|--------------------------------------------------------------------------
|
| Runtime dials and per-plan entitlements for the managed queue product.
| The product SURFACE is gated by the Pennant flag `surface.queue` in
| config/features.php — per the layering rules documented at
| config/features.php:11-26, Pennant answers "does this org get the product"
| and this file answers "how does it behave and what does it cost".
|
| See docs/adr/dply-queue.md.
|
| Note the deliberate distinction from Laravel's own config/queue.php: that
| one configures the queue dply ITSELF runs (Redis + Horizon). This one
| configures the queue dply SELLS. They share nothing.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | Off until the data plane ships. Mirrors `server_logs.enabled`.
    |
    */

    'enabled' => filter_var(env('DPLY_QUEUE_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Public endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL customers point their queue client at. Built from
    | DPLY_PUBLIC_APP_URL when unset, for the same reason the serverless log
    | ingest URL is: APP_URL is typically a local *.test in development and a
    | customer's app could never reach it.
    |
    */

    'public_url' => env('DPLY_QUEUE_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Reservation
    |--------------------------------------------------------------------------
    |
    | `default_visibility` is how long a claimed job stays invisible before it
    | becomes claimable again. `lease_grace` is added to the job's own declared
    | timeout when clamping — the clamp is what makes Laravel's
    | `retry_after > timeout` misconfiguration unrepresentable here.
    |
    | `max_visibility` bounds the damage a client can do by asking for an
    | enormous lease on a job it then abandons.
    |
    */

    'reservation' => [
        'default_visibility_seconds' => (int) env('DPLY_QUEUE_DEFAULT_VISIBILITY', 60),
        'lease_grace_seconds' => (int) env('DPLY_QUEUE_LEASE_GRACE', 15),
        'max_visibility_seconds' => (int) env('DPLY_QUEUE_MAX_VISIBILITY', 43200),
        'max_receive_count' => (int) env('DPLY_QUEUE_MAX_RECEIVE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Long polling
    |--------------------------------------------------------------------------
    |
    | Capped low on purpose for v1: a held request pins a PHP-FPM worker, so a
    | long wait multiplied by many concurrent drains exhausts FPM long before
    | it troubles Postgres. Even 2s is a large reduction against a worker's
    | default `--sleep=3`. Raising this meaningfully requires the dedicated
    | Octane pop pool (see the ADR), not just a bigger number here.
    |
    */

    'long_poll' => [
        'default_seconds' => (int) env('DPLY_QUEUE_LONG_POLL_DEFAULT', 2),
        'max_seconds' => (int) env('DPLY_QUEUE_LONG_POLL_MAX', 5),
        'interval_ms' => (int) env('DPLY_QUEUE_LONG_POLL_INTERVAL_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Metered on jobs PUSHED — not API requests, which would bill the customer
    | for dply's polling design and reward us for not improving it.
    |
    | Ships dark: disabled, rate zero. Same staging as Logs.
    |
    */

    'billing' => [
        'enabled' => filter_var(env('DPLY_QUEUE_BILLING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'per_million_jobs_cents' => (int) env('DPLY_QUEUE_PER_MILLION_JOBS_CENTS', 0),
        'markup_percent' => (int) env('DPLY_QUEUE_MARKUP_PERCENT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Defaults overlaid with a per-plan override. A limit of 0 means unlimited
    | — the fail-open convention from ServerLogEntitlement: nothing is enforced
    | until a number is deliberately set.
    |
    */

    'entitlements' => [
        'defaults' => [
            'available' => true,
            'tier' => 'standard',
            'monthly_included_jobs' => 1_000_000,
            'overage_per_million_jobs_cents' => 0,
            'max_namespaces' => 1,
            'max_queue_depth' => 100_000,
            'max_payload_bytes' => 262_144,
            'requests_per_minute' => 600,
            'hard_cap_jobs' => 0,
        ],

        'plans' => [
            'free' => [
                'max_namespaces' => 1,
                'max_queue_depth' => 10_000,
                'monthly_included_jobs' => 100_000,
                'requests_per_minute' => 120,
            ],
            'starter' => [
                'max_namespaces' => 2,
                'max_queue_depth' => 50_000,
            ],
            'pro' => [
                'max_namespaces' => 10,
                'max_queue_depth' => 500_000,
                'monthly_included_jobs' => 5_000_000,
                'requests_per_minute' => 1_200,
            ],
            'business' => [
                'max_namespaces' => 0,
                'max_queue_depth' => 2_000_000,
                'monthly_included_jobs' => 25_000_000,
                'requests_per_minute' => 3_000,
            ],
        ],
    ],

];
