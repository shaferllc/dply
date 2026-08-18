<?php

/*
|--------------------------------------------------------------------------
| Server health & capacity cockpit
|--------------------------------------------------------------------------
|
| Thresholds for the VM server health workspace — capacity warnings, cert
| expiry windows, and stale metrics detection.
|
*/

return [

    'capacity' => [
        'warning_pct' => (float) env('DPLY_SERVER_HEALTH_WARNING_PCT', 75),
        'critical_pct' => (float) env('DPLY_SERVER_HEALTH_CRITICAL_PCT', 90),
    ],

    'metrics_stale_minutes' => max(5, (int) env('DPLY_SERVER_HEALTH_METRICS_STALE_MINUTES', 10)),

    'certificates' => [
        'warning_days' => max(1, (int) env('DPLY_SERVER_HEALTH_CERT_WARNING_DAYS', 30)),
        'critical_days' => max(1, (int) env('DPLY_SERVER_HEALTH_CERT_CRITICAL_DAYS', 7)),
    ],

    'deployments' => [
        'lookback_days' => max(1, (int) env('DPLY_SERVER_HEALTH_DEPLOY_LOOKBACK_DAYS', 7)),
    ],

    'ui' => [
        'poll_seconds' => max(30, min(300, (int) env('DPLY_SERVER_HEALTH_POLL_SECONDS', 60))),
    ],

];
