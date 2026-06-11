<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Live "quick download" cap
    |--------------------------------------------------------------------------
    |
    | Quick downloads stage a fresh artifact (DB dump, files tar, .env, vhost,
    | logs, full home dir, or a combined bundle) into a temp file on the server,
    | stat it, and stream it straight to the browser — no S3, no control-plane
    | persistence. Because the whole payload is proxied through the control
    | plane over a single held-open HTTP connection, we refuse anything over
    | this ceiling and point the operator at the scheduled backup -> S3 path
    | instead. Default 250 MB.
    |
    */
    'max_bytes' => (int) env('DPLY_QUICK_DOWNLOAD_MAX_BYTES', 262_144_000),

    // Per-step SSH timeout (build + stream phases) in seconds.
    'timeout_seconds' => (int) env('DPLY_QUICK_DOWNLOAD_TIMEOUT_SECONDS', 1800),
];
