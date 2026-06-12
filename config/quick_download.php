<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Quick download cap
    |--------------------------------------------------------------------------
    |
    | Quick downloads build a fresh artifact (DB dump, files tar, .env, vhost,
    | logs, full home dir, or a combined bundle) on the server, stat it to
    | enforce this cap BEFORE anything moves, then upload it to the operator-
    | managed download bucket where it is RETAINED until it expires (see
    | retention_minutes). Anything over this ceiling fails the build and the
    | operator is pointed at the scheduled backup -> destination path instead.
    | Default 250 MB.
    |
    */
    'max_bytes' => (int) env('DPLY_QUICK_DOWNLOAD_MAX_BYTES', 262_144_000),

    /*
    |--------------------------------------------------------------------------
    | Retention window
    |--------------------------------------------------------------------------
    |
    | How long a built artifact is kept in the download bucket before the sweeper
    | ({@see \App\Console\Commands\PruneQuickDownloadsCommand}) prunes it. The
    | download link stays valid — and the file re-downloadable — for this whole
    | window; it is no longer deleted on first download. Default 4 hours.
    |
    */
    'retention_minutes' => (int) env('DPLY_QUICK_DOWNLOAD_RETENTION_MINUTES', 240),

    /*
    |--------------------------------------------------------------------------
    | Email-notification threshold
    |--------------------------------------------------------------------------
    |
    | Every ready artifact drops an in-app inbox item; artifacts at or above this
    | measured size ALSO send a transactional email (so a 1 KB .env doesn't also
    | email). Nothing auto-downloads — the requester always grabs it from the
    | notification link. Default 10 MB.
    |
    */
    'notify_threshold_bytes' => (int) env('DPLY_QUICK_DOWNLOAD_NOTIFY_THRESHOLD_BYTES', 10_485_760),

    // Per-step SSH timeout (build + upload phases) in seconds.
    'timeout_seconds' => (int) env('DPLY_QUICK_DOWNLOAD_TIMEOUT_SECONDS', 1800),
];
