<?php

return [
    /*
     * dply-managed realtime log drain endpoint. When a site attaches the
     * dply_realtime provider dply injects LOG_CHANNEL=papertrail with these
     * values, using Laravel's built-in Papertrail driver — no extra packages.
     */
    'dply_realtime' => [
        'host' => env('DPLY_LOG_DRAIN_HOST', ''),
        'port' => env('DPLY_LOG_DRAIN_PORT', ''),
    ],

    /*
     * Retention for received app-log records (app_logs). Pruned daily by
     * PruneAppLogsCommand so the main DB stays bounded.
     */
    'retention_days' => max(1, min(365, (int) env('DPLY_LOG_DRAIN_RETENTION_DAYS', 30))),

    /*
     * Per-site ingest rate limit on the drain receiver — a chatty or abusive app
     * can't flood app_logs. A site over the cap has its excess datagrams dropped
     * for the rest of the window. max_per_window = 0 disables the limit.
     */
    'rate_limit' => [
        'max_per_window' => max(0, (int) env('DPLY_LOG_DRAIN_RATE_MAX', 600)),
        'window_seconds' => max(1, (int) env('DPLY_LOG_DRAIN_RATE_WINDOW', 60)),
    ],
];
