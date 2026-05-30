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
    | Site load attribution snapshots + rolling history
    |--------------------------------------------------------------------------
    */
    'attribution' => [
        'snapshot_ttl_hours' => (int) env('SERVER_SHARED_HOST_ATTRIBUTION_TTL_HOURS', 1),
        'meta_key' => 'shared_host_attribution_snapshot',
        'history_meta_key' => 'shared_host_attribution_history',
        'history_max_entries' => (int) env('SERVER_SHARED_HOST_HISTORY_MAX_ENTRIES', 336),
        'ranges' => [
            '24h' => 24,
            '7d' => 168,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-site soft budgets (share of attributable load)
    |--------------------------------------------------------------------------
    */
    'budgets' => [
        'meta_key' => 'shared_host_budgets',
        'alert_state_meta_key' => 'shared_host_alert_state',
        'default_cpu_share_pct' => (float) env('SERVER_SHARED_HOST_DEFAULT_CPU_BUDGET_PCT', 50),
        'default_mem_share_pct' => (float) env('SERVER_SHARED_HOST_DEFAULT_MEM_BUDGET_PCT', 50),
        'notify_cooldown_hours' => (int) env('SERVER_SHARED_HOST_ALERT_COOLDOWN_HOURS', 4),
    ],
];
