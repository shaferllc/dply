<?php

declare(strict_types=1);

return [

    'stale_scan_hours' => max(1, (int) env('SERVER_SECURITY_DIGEST_STALE_HOURS', 24)),

    'thresholds' => [
        'auth_failed_warning' => max(1, (int) env('SERVER_SECURITY_DIGEST_AUTH_FAILED_WARNING', 50)),
        'auth_failed_critical' => max(1, (int) env('SERVER_SECURITY_DIGEST_AUTH_FAILED_CRITICAL', 200)),
    ],

];
