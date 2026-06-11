<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Metrics UI (Livewire polling)
    |--------------------------------------------------------------------------
    |
    | Hidden wire:poll intervals on the server Metrics workspace (seconds).
    |
    */

    'ui' => [
        'poll_probe_seconds' => max(1, min(120, (int) env('DPLY_METRICS_UI_POLL_PROBE_SECONDS', 3))),
        'poll_remote_task_seconds' => max(1, min(60, (int) env('DPLY_METRICS_UI_POLL_REMOTE_TASK_SECONDS', 2))),
        'auto_refresh_seconds' => max(15, min(3600, (int) env('DPLY_METRICS_UI_AUTO_REFRESH_SECONDS', 60))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest script (deployed over SSH with “Install Python for monitoring”)
    |--------------------------------------------------------------------------
    |
    | resources/server-scripts/server-metrics-snapshot.py is copied to this path
    | under the deploy user’s home directory.
    |
    | After each collect, if the remote file’s SHA-256 differs from the bundled
    | file (or the file is missing while metrics ran via inline fallback),
    | UpgradeGuestMetricsScriptJob deploys the latest script without apt.
    |
    */

    'guest_script' => [
        'relative_path' => '.dply/bin/server-metrics-snapshot.py',
        'auto_upgrade_on_collect' => (bool) env('DPLY_METRICS_GUEST_SCRIPT_AUTO_UPGRADE', true),
        'upgrade_queue' => env('SERVER_METRICS_GUEST_SCRIPT_UPGRADE_QUEUE', 'dply'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest → app HTTP push (continuous monitoring)
    |--------------------------------------------------------------------------
    |
    | After “Install monitoring”, a per-server token is created and
    | DeployGuestMetricsCallbackEnvJob writes ~/.dply/metrics-callback.env and
    | installs a user crontab block that runs python3 ~/.dply/bin/server-metrics-
    | snapshot.py on a schedule. The callback URL uses the unified /api/metrics
    | endpoint, preferring server_metrics.ingest.url, then dply.public_app_url,
    | then app.url.
    |
    */

    'guest_push' => [
        'enabled' => (bool) env('DPLY_METRICS_GUEST_PUSH_ENABLED', true),
        'deploy_queue' => env('SERVER_METRICS_GUEST_PUSH_DEPLOY_QUEUE', 'dply'),

        /** Five-field cron expression for the guest user crontab (path uses $HOME). */
        'cron_expression' => env('DPLY_METRICS_GUEST_PUSH_CRON', '* * * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Charts (Metrics page)
    |--------------------------------------------------------------------------
    |
    | Snapshots are stored by each collect; the UI graphs the newest N points.
    | Collector prunes older rows separately (see ServerMetricsCollector).
    |
    */

    'chart' => [
        'max_points' => max(12, min(500, (int) env('DPLY_METRICS_CHART_POINTS', 96))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Per-server row-count cap on ServerMetricSnapshot. At 1-min ingest cadence,
    | 10080 ≈ 7 days. Bumped from 800 (~13h) when we added the webserver/edge
    | health charts whose range picker goes out to 7 days. Time-based deletion
    | (anything older than 30 days) is a separate hard cap.
    |
    */
    'retention' => [
        'rows_per_server' => max(100, (int) env('DPLY_METRICS_RETENTION_ROWS', 10080)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webserver / edge-proxy health alert thresholds
    |--------------------------------------------------------------------------
    |
    | Hardcoded fallbacks for the alerting layer. Resolution order in
    | WebserverHealthThresholdResolver is:
    |   server+engine override → server override → org+engine → org → these fallbacks.
    |
    | `comparator` is one of {gt, gte, lt, lte}. `value` is compared against
    | the relevant field in the latest webserver_health block:
    |   - daemon_silent_seconds: how long with no successful scrape before
    |     we consider the engine down. Read from time-since-last-snapshot.
    |   - errors_5xx_per_min: rate from the most recent two-snapshot delta.
    |   - active_connections: gauge.
    |
    */
    'health_thresholds' => [
        // Critical: engine is silent (no scrape success) for more than 2 minutes.
        'daemon_silent_seconds' => ['comparator' => 'gt', 'value' => 120, 'severity' => 'critical'],
        // Warn: 5xx error rate exceeds 10/min in the latest sample window.
        'errors_5xx_per_min' => ['comparator' => 'gt', 'value' => 10, 'severity' => 'warning'],
        // Warn: active connections > 5000 (likely saturation; engine-specific tune).
        'active_connections' => ['comparator' => 'gt', 'value' => 5000, 'severity' => 'warning'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SSH probe (Metrics page — background)
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'queue' => env('SERVER_METRICS_PROBE_QUEUE', 'dply'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote metrics ingest (background)
    |--------------------------------------------------------------------------
    |
    | After each snapshot is stored, a queued job POSTs the payload to this
    | URL. Set DPLY_METRICS_INGEST_ENABLED=true where workers should forward
    | metrics (same value as QUEUE_CONNECTION=redis + Horizon/queue:work).
    |
    | Receiving app: POST /api/metrics with Bearer DPLY_METRICS_INGEST_TOKEN
    | (this repo stores rows in server_metric_ingest_events). Point URL at
    | your own APP_URL/api/metrics for a self-hosted stats sink, or leave the
    | default tunnel host.
    |
    */

    'ingest' => [
        'enabled' => (bool) env('DPLY_METRICS_INGEST_ENABLED', false),

        // No baked-in default: when DPLY_METRICS_INGEST_URL is unset, the guest
        // callback URL falls through to DPLY_PUBLIC_APP_URL (then APP_URL) — see
        // ServerMetricsGuestPushService::guestPushUrl(). A hardcoded default here
        // would always win that preference order and pin callbacks to a stale host.
        'url' => env('DPLY_METRICS_INGEST_URL'),

        /** Bearer token for outbound POST and inbound POST /api/metrics (must match) */
        'token' => env('DPLY_METRICS_INGEST_TOKEN'),

        'timeout' => (int) env('DPLY_METRICS_INGEST_TIMEOUT', 15),

        'queue' => env('SERVER_METRICS_INGEST_QUEUE', 'dply'),
    ],

];
