<?php

use Illuminate\Support\Str;

/*
| Horizon only processes the Redis queue driver. Set QUEUE_CONNECTION=redis for queued jobs
| (cron “Run now”, manage SSH, etc.) to appear here. Optional dedicated queue names are merged in
| so workers listen on those Redis lists too.
*/
$horizonExtraQueues = [];
foreach (['SERVER_CRON_RUN_QUEUE', 'SERVER_MANAGE_REMOTE_TASK_QUEUE', 'SERVER_METRICS_INGEST_QUEUE', 'SERVER_METRICS_PROBE_QUEUE', 'SERVER_METRICS_GUEST_SCRIPT_UPGRADE_QUEUE', 'SERVER_METRICS_GUEST_PUSH_DEPLOY_QUEUE', 'SERVER_DATABASE_EXPORT_QUEUE', 'SERVER_SYSTEMD_SYNC_QUEUE'] as $envKey) {
    $q = env($envKey);
    if (is_string($q) && $q !== '') {
        $horizonExtraQueues[] = $q;
    }
}
// Uptime probe queues, one per configured worker. A dedicated regional probe
// box consumes ONLY its own queue (set DPLY_PROBE_WORKER_QUEUE on the box) so
// checks for that region egress from that location. Central/dev Horizon drains
// every probe queue as a fallback, so regions without a deployed worker still
// get checked from the center instead of going stale.
$siteUptimeConfig = require __DIR__.'/site_uptime.php';
$probeQueues = [];
foreach (($siteUptimeConfig['probe_workers'] ?? []) as $worker) {
    if (is_string($worker['queue'] ?? null) && $worker['queue'] !== '') {
        $probeQueues[] = $worker['queue'];
    }
}

$probeWorkerQueue = env('DPLY_PROBE_WORKER_QUEUE');
// dply manages a worker pool's Horizon entirely through env vars it writes to
// the box (see WorkerPoolManager / PushWorkerPoolHorizonConfigJob). HORIZON_QUEUES
// is the explicit, dply-controlled queue list — when present it wins over the
// auto-derived set so the pool's "Queues watched" UI is the source of truth.
$horizonQueuesOverride = env('HORIZON_QUEUES');
if (is_string($horizonQueuesOverride) && trim($horizonQueuesOverride) !== '') {
    $horizonWorkerQueues = array_values(array_filter(array_map('trim', explode(',', $horizonQueuesOverride)), fn ($q) => $q !== ''));
} elseif (is_string($probeWorkerQueue) && $probeWorkerQueue !== '') {
    $horizonWorkerQueues = [$probeWorkerQueue];
} else {
    // dply's control plane drains its OWN namespace — 'dply' (the default queue,
    // see config/queue.php) + 'dply-control' (worker-pool orchestration) — NOT
    // bare 'default'. A managed worker app can run dply's codebase against the
    // same Redis (worker pools); if both consumed 'default' they'd steal each
    // other's jobs. Pool members are pushed HORIZON_QUEUES=default + REDIS_QUEUE
    // =default, so they own 'default' and the control plane owns 'dply'.
    // 'dply-provision' is listed FIRST so it has top dispatch priority — server
    // provisioning jobs jump ahead of routine control-plane work. It's always
    // WATCHED here (so routing a job to it can never silently stall), but jobs
    // only land on it when server_provision.queue is set to it (default 'dply').
    $horizonWorkerQueues = array_values(array_unique(array_merge(['default', 'dply-provision', 'dply', 'dply-control', 'dply-manage'], $horizonExtraQueues, $probeQueues)));
}

// dply-managed Horizon worker knobs — all env-driven so the pool UI can tune
// them without editing this file. Sensible fallbacks keep stock behaviour.
$horizonBalance = (string) (env('HORIZON_BALANCE') ?: 'auto');
$horizonMinProcesses = max(1, (int) env('HORIZON_MIN_PROCESSES', 1));
$horizonWorkerMemory = max(32, (int) env('HORIZON_WORKER_MEMORY', 128));
$horizonWorkerTries = max(1, (int) env('HORIZON_TRIES', 1));

// Total worker ceiling for the supervisor. Under the 'auto' balancer Horizon
// scales the pool between minProcesses (kept warm PER watched queue) and
// maxProcesses (the SUPERVISOR-WIDE total). With this many watched queues a flat
// ceiling of 10 can't staff every list, so the lower-priority queues starve under
// load. Default the cap to ~3 processes per watched queue (never below 10) so the
// pool can fan out across all of them, while staying fully overridable per box via
// HORIZON_MAX_PROCESSES. The floor also guarantees minProcesses can be honoured for
// every queue (count * minProcesses) before any extra headroom is added.
$horizonQueueCount = max(1, count($horizonWorkerQueues));
$horizonMaxProcessesFloor = max(10, $horizonQueueCount * $horizonMinProcesses);
$horizonMaxProcesses = max(
    $horizonMaxProcessesFloor,
    (int) env('HORIZON_MAX_PROCESSES', max($horizonMaxProcessesFloor, $horizonQueueCount * 3))
);
// How aggressively 'auto' rebalances: how many processes it may add/remove per
// scaling decision (balanceMaxShift) and how long it waits between decisions
// (balanceCooldown, seconds). Higher shift + lower cooldown = the pool reaches its
// ceiling faster when a queue backs up, instead of trickling one process at a time.
$horizonBalanceMaxShift = max(1, (int) env('HORIZON_BALANCE_MAX_SHIFT', 3));
$horizonBalanceCooldown = max(1, (int) env('HORIZON_BALANCE_COOLDOWN', 2));

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Allowed emails (non-local environments)
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of user emails that may access Horizon when APP_ENV
    | is not local. Local development allows any authenticated user.
    |
    */

    'allowed_emails' => env('HORIZON_ALLOWED_EMAILS', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            // Worker process kill bound. Must be ≥ the longest-running job's $timeout —
            // currently ApplyInsightFixJob at 700s (apt-security-updates fix can run for
            // 10 minutes on busy boxes). Override per-environment via HORIZON_*_JOB_TIMEOUT.
            'timeout' => (int) env('HORIZON_JOB_TIMEOUT', 720),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => $horizonWorkerQueues,
                'balance' => $horizonBalance,
                'minProcesses' => $horizonMinProcesses,
                'maxProcesses' => $horizonMaxProcesses,
                'memory' => $horizonWorkerMemory,
                'tries' => $horizonWorkerTries,
                'balanceMaxShift' => $horizonBalanceMaxShift,
                'balanceCooldown' => $horizonBalanceCooldown,
                'timeout' => (int) env('HORIZON_PROD_JOB_TIMEOUT', env('HORIZON_JOB_TIMEOUT', 720)),
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => $horizonWorkerQueues,
                'balance' => (string) (env('HORIZON_BALANCE') ?: 'simple'),
                'minProcesses' => $horizonMinProcesses,
                // Concurrent workers for local dev (Redis default maxclients is usually plenty).
                'maxProcesses' => max($horizonMinProcesses, (int) env('HORIZON_LOCAL_MAX_PROCESSES', $horizonMaxProcesses)),
                'memory' => max(32, (int) env('HORIZON_LOCAL_WORKER_MEMORY', $horizonWorkerMemory)),
                'tries' => $horizonWorkerTries,
                'balanceMaxShift' => $horizonBalanceMaxShift,
                'balanceCooldown' => $horizonBalanceCooldown,
                'timeout' => (int) env('HORIZON_LOCAL_JOB_TIMEOUT', 720),
                'nice' => 0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

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
