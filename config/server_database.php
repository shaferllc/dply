<?php

return [

    /**
     * Try root SSH first for local database probes and admin commands, then fall back to the
     * configured SSH user when root login is unavailable.
     */
    'use_root_ssh' => (bool) env('SERVER_DATABASE_USE_ROOT_SSH', true),

    'fallback_to_deploy_user_ssh' => (bool) env('SERVER_DATABASE_FALLBACK_TO_DEPLOY_SSH', true),

    /**
     * Cache SSH database-engine probes (MySQL/MariaDB root + PostgreSQL) to avoid a round trip on every Livewire render.
     */
    'capabilities_cache_ttl_seconds' => (int) env('SERVER_DATABASE_CAPABILITIES_TTL', 120),

    /** Max SQL file size for one-shot imports (bytes). */
    'import_max_bytes' => (int) env('SERVER_DATABASE_IMPORT_MAX_BYTES', 10485760),

    /**
     * Optional dedicated queue for SQL export jobs (Horizon merges this name into workers when set).
     */
    'export_queue' => env('SERVER_DATABASE_EXPORT_QUEUE'),

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
