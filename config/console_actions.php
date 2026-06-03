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
        'env_scan' => [
            'running' => 'Scanning code for required variables on :host …',
            'completed' => 'Environment requirements re-scanned.',
            'failed' => 'Environment scan failed.',
            'stale' => 'Environment scan did not finish.',
        ],
        'binding_validate' => [
            'running' => 'Validating the connection from :host …',
            'completed' => 'Connection validated — the server can reach the resource.',
            'failed' => 'Connection check failed — the server could not reach the resource.',
            'stale' => 'Connection check did not finish.',
        ],
        'site_test' => [
            'running' => 'Testing whether the site loads …',
            'completed' => 'The site loaded successfully.',
            'failed' => 'The site did not load — see the error output.',
            'stale' => 'Site test did not finish.',
        ],
        'site_remediate' => [
            'running' => 'Running the fix on :host …',
            'completed' => 'Fix completed.',
            'failed' => 'The fix did not complete — see the output.',
            'stale' => 'Fix did not finish.',
        ],
        'pipeline_optimize' => [
            'running' => 'Reading package.json / composer.json on :host …',
            'completed' => 'Pipeline optimized.',
            'failed' => 'Pipeline optimize did not complete — see the output.',
            'stale' => 'Pipeline optimize did not finish.',
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
        'webserver_switch' => [
            'running' => 'Switching webserver on :host …',
            'completed' => 'Webserver switched.',
            'failed' => 'Webserver switch failed.',
            'stale' => 'Webserver switch did not finish.',
        ],
        // Generic "operator pressed a button" surface for the runAllowlistedAction
        // path (restart-nginx, caddy fmt, certbot renew, etc.). The per-action
        // label comes from the service_actions allowlist and is passed to the
        // banner via the run's `label` field, so this default copy is rarely
        // shown — it's a fallback when a caller omits the label.
        'manage_action' => [
            'running' => 'Running action on :host …',
            'completed' => 'Action complete.',
            'failed' => 'Action failed.',
            'stale' => 'Action did not finish.',
        ],
        'edge_proxy' => [
            'running' => 'Edge proxy action running on :host …',
            'completed' => 'Edge proxy update complete.',
            'failed' => 'Edge proxy action failed.',
            'stale' => 'Edge proxy action did not finish.',
        ],
        // Inventory probe streamed into the Manage banner. The probe runs
        // synchronously from RunsServerInventoryProbe; the seeded row gives
        // every Manage caller (Tools, Overview, Services) the same console
        // surface they get for install / restart actions.
        'inventory_probe' => [
            'running' => 'Probing :host inventory …',
            'completed' => 'Inventory refreshed.',
            'failed' => 'Inventory probe failed.',
            'stale' => 'Inventory probe did not finish.',
        ],
        // Server clone — DigitalOcean droplet snapshot → new droplet. Long
        // running (3–10 min); the banner mounts on the cloned server's
        // workspace immediately so the operator can watch progress.
        'clone_server' => [
            'running' => 'Cloning to :host …',
            'completed' => 'Clone ready.',
            'failed' => 'Clone failed.',
            'stale' => 'Clone did not finish.',
        ],
        'php_load_config' => [
            'running' => 'Loading PHP config from :host …',
            'completed' => 'PHP config loaded.',
            'failed' => 'PHP config load failed.',
            'stale' => 'PHP config load did not finish.',
        ],
        'php_save_config' => [
            'running' => 'Saving PHP config on :host …',
            'completed' => 'PHP config saved.',
            'failed' => 'PHP config save failed.',
            'stale' => 'PHP config save did not finish.',
        ],
        'php_refresh_inventory' => [
            'running' => 'Refreshing PHP inventory on :host …',
            'completed' => 'PHP inventory refreshed.',
            'failed' => 'PHP inventory refresh failed.',
            'stale' => 'PHP inventory refresh did not finish.',
        ],
        'php_install' => [
            'running' => 'Installing PHP on :host …',
            'completed' => 'PHP install complete.',
            'failed' => 'PHP install failed.',
            'stale' => 'PHP install did not finish.',
        ],
        'php_set_cli_default' => [
            'running' => 'Setting PHP CLI default on :host …',
            'completed' => 'PHP CLI default updated.',
            'failed' => 'PHP CLI default update failed.',
            'stale' => 'PHP CLI default update did not finish.',
        ],
        'php_set_new_site_default' => [
            'running' => 'Setting PHP new-site default on :host …',
            'completed' => 'PHP new-site default updated.',
            'failed' => 'PHP new-site default update failed.',
            'stale' => 'PHP new-site default update did not finish.',
        ],
        'php_patch' => [
            'running' => 'Patching PHP on :host …',
            'completed' => 'PHP patch complete.',
            'failed' => 'PHP patch failed.',
            'stale' => 'PHP patch did not finish.',
        ],
        'php_uninstall' => [
            'running' => 'Uninstalling PHP on :host …',
            'completed' => 'PHP uninstall complete.',
            'failed' => 'PHP uninstall failed.',
            'stale' => 'PHP uninstall did not finish.',
        ],
        'php_migrate_sites' => [
            'running' => 'Moving PHP sites on :host …',
            'completed' => 'PHP sites upgraded.',
            'failed' => 'PHP site upgrade failed.',
            'stale' => 'PHP site upgrade did not finish.',
        ],
        'db_engine_install' => [
            'running' => 'Installing database engine on :host …',
            'completed' => 'Database engine installed.',
            'failed' => 'Database engine install failed.',
            'stale' => 'Database engine install did not finish.',
        ],
        'db_engine_uninstall' => [
            'running' => 'Uninstalling database engine on :host …',
            'completed' => 'Database engine uninstalled.',
            'failed' => 'Database engine uninstall failed.',
            'stale' => 'Database engine uninstall did not finish.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server workspace console kinds
    |--------------------------------------------------------------------------
    | Each server workspace page shows only console runs whose kind is listed
    | here. Prevents an edge-proxy install banner from appearing on Webserver
    | (and vice versa) when both share the same Server subject.
    */
    'server_workspace_kinds' => [
        'webserver' => ['webserver_switch', 'manage_action'],
        'edge-proxy' => ['edge_proxy'],
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
        'environment' => ['env_sync', 'env_push', 'env_scan'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued-stall threshold
    |--------------------------------------------------------------------------
    | If status is still `queued` this many seconds after created_at, the UI
    | treats the run as failed (queue worker likely not running). Shorter than
    | `stale_after_seconds` so operators are not left waiting ten minutes.
    */
    'queued_stalled_after_seconds' => (int) env('DPLY_CONSOLE_ACTION_QUEUED_STALLED', 45),

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
