<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Console action kinds
    |--------------------------------------------------------------------------
    | Every backgrounded action that wants the page-top console banner declares
    | a kind here. The job claims its kind via WritesConsoleAction::consoleKind();
    | the banner getter picks display copy from this map. Adding a new kind is a
    | one-file change — no DB migration, no per-callsite editing.
    |
    | Each kind exposes:
    |   - running:   "Doing X to :host …"      (host placeholder = subject label)
    |   - completed: "X done."
    |   - failed:    "X failed."
    |   - stale:     "X did not finish."       (used when run is past staleness threshold)
    */
    'kinds' => [
        'webserver_config' => [
            'running' => 'Applying webserver config to :host …',
            'completed' => 'Webserver config applied.',
            'failed' => 'Webserver config apply failed.',
            'stale' => 'Webserver config apply did not finish.',
        ],
        'basic_auth_sync' => [
            'running' => 'Scanning :host for .htpasswd files …',
            'completed' => 'Basic-auth sync complete.',
            'failed' => 'Basic-auth sync failed.',
            'stale' => 'Basic-auth sync did not finish.',
        ],
        'env_sync' => [
            'running' => 'Reading .env from :host …',
            'completed' => 'Environment cache refreshed from server.',
            'failed' => 'Environment sync failed.',
            'stale' => 'Environment sync did not finish.',
        ],
        'env_push' => [
            'running' => 'Writing .env to :host …',
            'completed' => '.env written to server.',
            'failed' => '.env push failed.',
            'stale' => '.env push did not finish.',
        ],
        'ssl' => [
            'running' => 'Issuing SSL certificate on :host …',
            'completed' => 'SSL certificate issued.',
            'failed' => 'SSL certificate issuance failed.',
            'stale' => 'SSL issuance did not finish.',
        ],
        'system_user' => [
            'running' => 'Updating system user on :host …',
            'completed' => 'System user updated.',
            'failed' => 'System user update failed.',
            'stale' => 'System user update did not finish.',
        ],
        'systemd' => [
            'running' => 'Updating systemd units on :host …',
            'completed' => 'Systemd units updated.',
            'failed' => 'Systemd unit update failed.',
            'stale' => 'Systemd unit update did not finish.',
        ],
        'permissions' => [
            'running' => 'Resetting site permissions on :host …',
            'completed' => 'Site permissions reset.',
            'failed' => 'Permissions reset failed.',
            'stale' => 'Permissions reset did not finish.',
        ],
        'uptime_check' => [
            'running' => 'Running uptime check on :host …',
            'completed' => 'Uptime check complete.',
            'failed' => 'Uptime check failed.',
            'stale' => 'Uptime check did not finish.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Section kinds map
    |--------------------------------------------------------------------------
    | Maps a Site Settings section ID (the `$section` route param: 'general',
    | 'routing', 'basic-auth', …) to the kinds whose banner makes sense on that
    | section. Sections not listed here render no banner — operators visiting
    | /notifications, /logs, /environment etc. shouldn't see a credential-
    | rotation or webserver-apply banner that was triggered from another tab.
    |
    | The `Monitor` page is a separate Livewire component; it scopes itself to
    | `uptime_check` directly and does not consult this table.
    */
    'section_kinds' => [
        'routing' => ['webserver_config'],
        'certificates' => ['ssl', 'webserver_config'],
        'runtime' => ['webserver_config'],
        'system-user' => ['system_user', 'webserver_config', 'permissions'],
        'laravel-stack' => ['webserver_config'],
        'wordpress' => ['webserver_config'],
        'basic-auth' => ['basic_auth_sync', 'webserver_config'],
        'webserver-config' => ['webserver_config'],
        'environment' => ['env_sync', 'env_push'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-running threshold
    |--------------------------------------------------------------------------
    | If status is still 'running' this many seconds after started_at, the
    | banner shows the run as failed/stale and the dismiss button is allowed
    | to clear it. Guards against orphaned rows when a worker dies mid-job.
    */
    'stale_after_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | Output cap
    |--------------------------------------------------------------------------
    | Hard ceiling on the number of {t,level,source,line} entries we keep in
    | `output.lines`. Append trims to this many entries (latest wins) so the
    | JSON column stays well under Postgres's TOAST happy zone — at ~200 bytes
    | per entry that's roughly 1MB worst case.
    */
    'max_lines' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Output JSON wrapper version
    |--------------------------------------------------------------------------
    | Bump when the persisted shape changes. Readers should accept any version
    | <= the current one and adapt; writers always emit `current_version`.
    */
    'current_version' => 1,

];
