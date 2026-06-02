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
    | Probe region keys → display labels
    |--------------------------------------------------------------------------
    |
    | Cosmetic labels shown on the monitor row and any public status page. A
    | monitor's `probe_region` is derived from the worker it runs on (see
    | `probe_workers` below), so this map only governs how a region reads.
    |
    | @var array<string, string>
    */
    'probe_regions' => [
        'eu-falkenstein' => '[EU] Falkenstein',
        'eu-amsterdam' => '[EU] Amsterdam',
        'eu-frankfurt' => '[EU] Frankfurt',
        'us-east' => '[US] N. Virginia',
        'us-west' => '[US] Oregon',
        'ap-sydney' => '[APAC] Sydney',
    ],

    /*
    |--------------------------------------------------------------------------
    | Probe workers (real multi-region egress)
    |--------------------------------------------------------------------------
    |
    | Each worker is a Horizon node that consumes its own `queue`; a check
    | dispatched onto that queue runs the HTTP GET from the worker's location.
    | `region` points at a `probe_regions` key and supplies the monitor's
    | cosmetic label. Add an entry only once the box is deployed and listening
    | on its queue — there is no liveness detection in v1, so a configured
    | worker is assumed live. The order here is the fallback order when the
    | host's nearest region has no deployed worker (the first entry wins).
    |
    | Per-box: set DPLY_PROBE_WORKER_QUEUE=<queue> so a regional box consumes
    | only its own probe queue. Central/dev Horizon drains every probe queue
    | as a fallback so regions without a deployed worker still get checked.
    |
    | Today: one worker — worker-1.dply.io, Hetzner Falkenstein (fsn1, network
    | zone eu-central). Set DPLY_PROBE_WORKER_QUEUE=probes:worker-1 on that box.
    |
    | @var array<string, array{region: string, queue: string}>
    */
    'probe_workers' => [
        'worker-1' => ['region' => 'eu-falkenstein', 'queue' => 'probes:worker-1'],
    ],

];
