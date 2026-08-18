<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | Rows older than this many days are pruned by dply:prune-audit-logs.
    | Minimum enforced floor of 30 days regardless of configured value.
    |
    */

    'retention_days' => (int) env('DPLY_AUDIT_RETENTION_DAYS', 365),
];
