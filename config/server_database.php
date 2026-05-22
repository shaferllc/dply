<?php

return [

    /**
     * Try root SSH first for local database probes and admin commands, then fall back to the
     * configured SSH user when root login is unavailable.
     */
    'use_root_ssh' => (bool) env('SERVER_DATABASE_USE_ROOT_SSH', true),

    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_DATABASE_FALLBACK_TO_DEPLOY_SSH', true),

    /**
     * Cache SSH database-engine probes (MySQL/MariaDB root + PostgreSQL + sqlite3) to avoid an SSH round trip on every Livewire render.
     */
    'capabilities_cache_ttl_seconds' => (int) env('SERVER_DATABASE_CAPABILITIES_TTL', 120),

    /** Max SQL file size for one-shot imports (bytes). */
    'import_max_bytes' => (int) env('SERVER_DATABASE_IMPORT_MAX_BYTES', 10485760),

    /**
     * Filesystem root SQLite database files must live under. The
     * provisioner refuses to create or drop SQLite files outside this
     * tree so a malformed `host` field can never wipe `/etc` or similar.
     */
    'sqlite_root' => (string) env('SERVER_DATABASE_SQLITE_ROOT', '/var/lib/dply/sqlite'),

    /**
     * Additional roots SQLite database files are allowed to live under in addition to `sqlite_root`.
     * Lets `safeSqlitePath()` accept site-relative paths (e.g. /home/dply/{slug}/current/database/database.sqlite)
     * created by the Laravel scaffold pipeline, so those rows can be dropped, SQL-consoled, and backed up too.
     *
     * Set via env as a comma-separated list, e.g. SERVER_DATABASE_SQLITE_EXTRA_ROOTS="/home/dply,/srv/sites".
     *
     * @var list<string>
     */
    'sqlite_extra_safe_roots' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SERVER_DATABASE_SQLITE_EXTRA_ROOTS', '/home/dply'))
    ))),

    /**
     * Optional dedicated queue for SQL export jobs (Horizon merges this name into workers when set).
     */
    'export_queue' => env('SERVER_DATABASE_EXPORT_QUEUE'),

    /**
     * Optional dedicated queue for engine install / uninstall jobs (apt round-trips can take
     * minutes on small boxes; isolating them keeps short-running queue work snappy).
     */
    'install_queue' => env('SERVER_DATABASE_INSTALL_QUEUE'),

    /**
     * Disk used to store completed database backups. 'local' keeps them on the Dply web app's filesystem;
     * set to any disk in config/filesystems.php (e.g. 's3') to ship backups off-host.
     */
    'backup_disk' => env('SERVER_DATABASE_BACKUP_DISK', 'local'),

    /**
     * Hard cap on a single SQLite .db backup snapshot (bytes). Backups above this fail with a clear error
     * pointing at the limit instead of OOMing the queue worker.
     */
    'sqlite_backup_max_bytes' => (int) env('SERVER_DATABASE_SQLITE_BACKUP_MAX_BYTES', 256 * 1024 * 1024),

    /**
     * Keep only the most-recent N completed backups per database. Older ones are pruned (file + row)
     * at the end of every successful export job.
     */
    'backup_retention_per_database' => (int) env('SERVER_DATABASE_BACKUP_RETENTION_PER_DATABASE', 10),

    /**
     * Time-based retention for {@see App\Console\Commands\PruneBackupsCommand} (days).
     * Complements the per-database count limit above — anything older than this is pruned
     * regardless of how many newer backups exist. Floor of 7 days enforced in code.
     */
    'run_retention_days' => (int) env('SERVER_DATABASE_BACKUP_RETENTION_DAYS', 90),

    /** Default expiry for credential share links (hours). */
    'credential_share_expires_hours' => (int) env('SERVER_DATABASE_SHARE_EXPIRES_HOURS', 72),

    /** Default max views per credential share link. */
    'credential_share_max_views' => (int) env('SERVER_DATABASE_SHARE_MAX_VIEWS', 3),

    /**
     * Organization JSON defaults for {@see Organization::mergedDatabaseWorkspaceSettings()}.
     * Per-org overrides live in organizations.database_workspace_settings.
     *
     * @var array{credential_shares_enabled: bool, import_max_bytes: int|null}
     */
    'organization_defaults' => [
        'credential_shares_enabled' => (bool) env('SERVER_DATABASE_ORG_SHARES_DEFAULT', true),
        /** null = org has no extra cap (use app import_max_bytes). Set in org JSON to lower the limit. */
        'import_max_bytes' => null,
    ],
];
