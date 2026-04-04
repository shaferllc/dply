<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable scheduled HTTP checks for site uptime monitors
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('DPLY_SITE_UPTIME_CHECKS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Publish notification events when a monitor transitions down / recovered
    |--------------------------------------------------------------------------
    */
    'notify_on_transitions' => filter_var(env('DPLY_SITE_UPTIME_NOTIFY_TRANSITIONS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | How often the scheduler dispatches check jobs (must match schedule cadence)
    |--------------------------------------------------------------------------
    */
    'check_interval_minutes' => max(1, min(60, (int) env('DPLY_SITE_UPTIME_CHECK_INTERVAL_MINUTES', 5))),

    /*
    |--------------------------------------------------------------------------
    | MonitorOperationalState: treat checks older than interval × multiplier as stale
    |--------------------------------------------------------------------------
    */
    'stale_check_multiplier' => max(1, min(10, (int) env('DPLY_SITE_UPTIME_STALE_MULTIPLIER', 2))),

    /*
    |--------------------------------------------------------------------------
    | Probe region keys → labels (v1: checks run from Dply worker egress)
    |--------------------------------------------------------------------------
    |
    | @var array<string, string>
    */
    'probe_regions' => [
        'eu-amsterdam' => '[EU] Amsterdam',
        'eu-frankfurt' => '[EU] Frankfurt',
        'us-east' => '[US] N. Virginia',
        'us-west' => '[US] Oregon',
        'ap-sydney' => '[APAC] Sydney',
    ],

];
