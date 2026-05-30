<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shared Host Radar — contention detection thresholds
    |--------------------------------------------------------------------------
    */
    'contention' => [
        'cpu_spike_pct' => (float) env('SERVER_SHARED_HOST_CPU_SPIKE_PCT', 85),
        'deploy_correlation_minutes' => (int) env('SERVER_SHARED_HOST_DEPLOY_WINDOW_MINUTES', 15),
        'dominant_site_pct' => (float) env('SERVER_SHARED_HOST_DOMINANT_SITE_PCT', 70),
        'max_events' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Site load attribution snapshot freshness
    |--------------------------------------------------------------------------
    */
    'attribution' => [
        'snapshot_ttl_hours' => (int) env('SERVER_SHARED_HOST_ATTRIBUTION_TTL_HOURS', 1),
        'meta_key' => 'shared_host_attribution_snapshot',
    ],
];
