<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Warm server pool
    |--------------------------------------------------------------------------
    | Pre-provisioned spares kept ready per provider×region×size×tier bucket so
    | a create can claim + personalize one instead of cold-provisioning. OFF by
    | default — no buckets means no warm servers and every create cold-provisions
    | exactly as today (the claim seam returns null).
    |
    | Idle warm servers cost money (platform cost, not customer-billed until
    | claimed), so keep buckets to genuinely hot combinations and small min/max.
    */
    'enabled' => (bool) env('DPLY_WARM_POOL_ENABLED', false),

    // The system organization that owns unclaimed warm servers. Resolved by the
    // autoscaler; create it once (ops) and set its id here.
    'pool_organization_id' => env('DPLY_WARM_POOL_ORG_ID', ''),

    /*
    | Buckets to keep warm. Each: provider, region, size, tier
    | ('baseline' | 'stack'), optional stack (for tier=stack), and min/max
    | watermarks. The autoscaler refills to `min` and retires idle above `max`.
    |
    | Example (managed-beta CX22 hot-stack + a DO baseline):
    |   [
    |     ['provider' => 'hetzner', 'region' => 'fsn1', 'size' => 'cx22',
    |      'tier' => 'stack', 'stack' => ['server_role'=>'application','webserver'=>'nginx',
    |        'php_version'=>'8.3','database'=>'mysql84','cache_service'=>'redis'],
    |      'min' => 2, 'max' => 4],
    |     ['provider' => 'digitalocean', 'region' => 'nyc1', 'size' => 's-2vcpu-2gb',
    |      'tier' => 'baseline', 'min' => 1, 'max' => 2],
    |   ]
    */
    'buckets' => [],

    // Don't hand out / keep a member whose last successful health check is older
    // than this (seconds) — re-check or replace first.
    'health_max_age_seconds' => (int) env('DPLY_WARM_POOL_HEALTH_MAX_AGE', 900),

    // A 'warming' member that never reaches ready/failed within this window is
    // treated as wedged and marked failed so the bucket refills. 0 = never.
    'max_warming_seconds' => (int) env('DPLY_WARM_POOL_MAX_WARMING', 1800),

    // Backstop: re-dispatch personalization for a claimed member whose server
    // hasn't finished setup after this grace window (covers a lost personalize
    // job). 0 = disabled.
    'personalize_backstop_seconds' => (int) env('DPLY_WARM_POOL_PERSONALIZE_BACKSTOP', 300),

    // Retire a ready member older than this (seconds) to bound security drift;
    // the autoscaler replaces it with a fresh (patched) one. 0 = never.
    'max_member_age_seconds' => (int) env('DPLY_WARM_POOL_MAX_AGE', 0),

    // Number of stale members the autoscaler retires per tick (gentle churn).
    'retire_cap_per_tick' => (int) env('DPLY_WARM_POOL_RETIRE_CAP', 1),

    /*
    | Off-hours scale-down. During the window the autoscaler treats each bucket's
    | min/max as `min` below (default 0 = scale to zero), so idle spares are
    | retired overnight and not refilled. Hours are 0-23 in the app timezone; the
    | window wraps midnight when start > end (e.g. 22→6).
    */
    'off_hours' => [
        'enabled' => (bool) env('DPLY_WARM_POOL_OFF_HOURS', false),
        'start' => (int) env('DPLY_WARM_POOL_OFF_HOURS_START', 22),
        'end' => (int) env('DPLY_WARM_POOL_OFF_HOURS_END', 6),
        'min' => (int) env('DPLY_WARM_POOL_OFF_HOURS_MIN', 0),
    ],
];
