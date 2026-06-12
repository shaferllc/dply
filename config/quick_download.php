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
    | managed download bucket for a one-time, single-use download. Anything over
    | this ceiling fails the build and the operator is pointed at the scheduled
    | backup -> destination path instead. Default 250 MB.
    |
    */
    'max_bytes' => (int) env('DPLY_QUICK_DOWNLOAD_MAX_BYTES', 262_144_000),

    /*
    |--------------------------------------------------------------------------
    | Notify-vs-auto-download threshold
    |--------------------------------------------------------------------------
    |
    | Everything still queues + builds on the box (no SSH in the request). What
    | differs by size is the post-build UX: artifacts at or above this measured
    | size are treated as "large" — we notify the requester in-app + by email and
    | let them grab it from the link. Smaller artifacts skip the notification and
    | the page poll just auto-downloads them within a second or two. Default 5 MB.
    |
    */
    'notify_threshold_bytes' => (int) env('DPLY_QUICK_DOWNLOAD_NOTIFY_THRESHOLD_BYTES', 5_242_880),

    // Per-step SSH timeout (build + upload phases) in seconds.
    'timeout_seconds' => (int) env('DPLY_QUICK_DOWNLOAD_TIMEOUT_SECONDS', 1800),
];
