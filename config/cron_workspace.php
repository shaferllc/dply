<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Run history retention
    |--------------------------------------------------------------------------
    |
    | `dply:prune-cron-job-runs` removes rows older than this many days.
    |
    */
    'run_retention_days' => (int) env('DPLY_CRON_RUN_RETENTION_DAYS', 90),

];
