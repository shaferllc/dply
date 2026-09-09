<?php

declare(strict_types=1);

return [

    'stale_scan_hours' => max(1, (int) env('SERVER_SECURITY_DIGEST_STALE_HOURS', 24)),

    'thresholds' => [
        // Unique source IPs with a Failed password / Invalid user line in the
        // last 24h. Lifetime auth.log line counts are informational only —
        // a public SSH port accumulates hundreds of scanner lines without
        // that meaning a live incident.
        'auth_failed_warning' => max(1, (int) env('SERVER_SECURITY_DIGEST_AUTH_FAILED_WARNING', 15)),
        'auth_failed_critical' => max(1, (int) env('SERVER_SECURITY_DIGEST_AUTH_FAILED_CRITICAL', 40)),
        'auth_failed_recent_warning' => max(1, (int) env('SERVER_SECURITY_DIGEST_AUTH_FAILED_RECENT_WARNING', 25)),
    ],

];
