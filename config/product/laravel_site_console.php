<?php

return [
    /*
    |--------------------------------------------------------------------------
    | One-click Artisan commands (site Laravel console)
    |--------------------------------------------------------------------------
    |
    | Keys are UI category labels; values are lists of exact `php artisan …` tails
    | (everything after `php artisan `) allowed for preset buttons.
    |
    */
    'preset_categories' => [
        'About' => ['about'],
        'Cache' => ['cache:clear'],
        'Config' => ['config:clear', 'config:cache'],
        'Database' => ['migrate:status'],
        'General' => ['down', 'up', 'version', 'env'],
        'Queue' => ['queue:failed', 'queue:flush', 'queue:restart', 'queue:retry all'],
        'Optimize' => ['optimize', 'optimize:clear'],
        'Route' => ['route:list', 'route:cache', 'route:clear'],
        'Storage' => ['storage:link'],
        'Scheduler' => ['schedule:list'],
        'View' => ['view:clear'],
    ],

    'list_cache_ttl_seconds' => 3600,
];
