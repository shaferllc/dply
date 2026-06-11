<?php

use App\Console\Commands\PruneBackupsCommand;

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
     * SSH exec timeout while creating the tar.gz on the server.
     */
    'timeout_seconds' => (int) env('SITE_FILE_BACKUP_TIMEOUT_SECONDS', 7200),

    /**
     * Root directory on the SITE'S server where durable archives are written
     * (mirrors server_database.remote_backup_root). Per-server subtree under it.
     */
    'remote_backup_root' => env('SITE_FILE_BACKUP_REMOTE_ROOT', '/var/lib/dply/site-file-backups'),

    /**
     * Per-server size cap for the remote archive tree; oldest archives are pruned
     * past this ceiling after each export.
     */
    'remote_backup_max_bytes_per_server' => (int) env('SITE_FILE_BACKUP_REMOTE_MAX_BYTES_PER_SERVER', 21474836480),

    /**
     * Time-based retention for {@see PruneBackupsCommand} (days).
     * Floor of 7 days enforced in code.
     */
    'run_retention_days' => (int) env('SITE_FILE_BACKUP_RETENTION_DAYS', 90),

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
