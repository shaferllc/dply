<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon
|--------------------------------------------------------------------------
|
| Queues are defined here — no HORIZON_QUEUES env needed.
|
|   supervisor-heavy → Edge builds, BYO deploys, long work
|   supervisor-fast  → notifications, insights, probes, short work
|
| Add a queue name below when you introduce a new Redis list.
|
*/

$heavyQueues = [
    'dply-provision', // Edge build/publish, server provision
    'dply',           // BYO deploys, general control-plane work
];

$fastQueues = [
    'default',         // notifications, most ShouldQueue jobs
    'dply-control',    // worker-pool orchestration
    'dply-manage',     // server manage / remote tasks
    'probes:worker-1', // uptime probes (mirror site_uptime.probe_workers)
];

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', 'auth'],

    'allowed_emails' => env('HORIZON_ALLOWED_EMAILS', ''),

    'waits' => [
        'redis:default' => 60,
        'redis:dply' => 60,
        'redis:dply-provision' => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-heavy' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'nice' => 0,
        ],
        'supervisor-fast' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'nice' => 0,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-heavy' => [
                'queue' => $heavyQueues,
                // Edge builds + provision are CPU/IO heavy — keep at least two
                // processes ready so a long Astro build doesn't stall the queue.
                'minProcesses' => (int) env('HORIZON_HEAVY_MIN_PROCESSES', 2),
                'maxProcesses' => (int) env('HORIZON_HEAVY_MAX_PROCESSES', 8),
                'timeout' => 7320,
            ],
            'supervisor-fast' => [
                'queue' => $fastQueues,
                'minProcesses' => 1,
                'maxProcesses' => 10,
                'timeout' => 900,
            ],
        ],

        'local' => [
            'supervisor-heavy' => [
                'queue' => $heavyQueues,
                'balance' => 'simple',
                'minProcesses' => (int) env('HORIZON_HEAVY_MIN_PROCESSES', 1),
                'maxProcesses' => (int) env('HORIZON_HEAVY_MAX_PROCESSES', 5),
                'timeout' => 7320,
            ],
            'supervisor-fast' => [
                'queue' => $fastQueues,
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'timeout' => 900,
            ],
        ],

    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
