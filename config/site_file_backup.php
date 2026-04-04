<?php

return [

    /**
     * Optional dedicated queue for site file export jobs (Horizon merges this name into workers when set).
     */
    'export_queue' => env('SITE_FILE_BACKUP_EXPORT_QUEUE'),

    /**
     * Maximum size of a downloaded archive (bytes). Streams stop and the job fails if exceeded.
     */
    'max_bytes' => (int) env('SITE_FILE_BACKUP_MAX_BYTES', 5368709120),

    /**
     * SSH exec timeout while streaming tar.gz from the server.
     */
    'timeout_seconds' => (int) env('SITE_FILE_BACKUP_TIMEOUT_SECONDS', 7200),

    /**
     * Path segments excluded from each full archive (relative to the site repository root).
     * Uses GNU tar --exclude; patterns apply to any matching path component.
     *
     * @var list<string>
     */
    'tar_excludes' => [
        'vendor',
        'node_modules',
        '.git',
        '.env',
        '.env.*',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'bootstrap/cache',
    ],

];
