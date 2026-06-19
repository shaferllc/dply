<?php

/**
 * Server workspace sidebar: `key` matches :active on x-server-workspace-shell.
 *
 * Nav visibility uses three mechanisms (see enablement table in config/features.php):
 *   - `feature` / `preview_feature` → Pennant org rollout (workspace.*)
 *   - `requires_any_tags`, `except_host_kinds`, `requires_min_sites` → per-server structure
 *   - `webserver_coming_soon` / `edge_proxy_coming_soon` below → global engine UI (not Pennant)
 * Rows without `feature` are core BYO (always on when structural rules pass).
 */
return [

    /**
     * Queued “Run now” for cron jobs: SSH runs in a worker; output streams via Reverb (and cache for poll fallback).
     */
    'cron_run' => [
        'cache_ttl_seconds' => (int) env('SERVER_CRON_RUN_CACHE_TTL', 900),
        'broadcast_chunk_interval_ms' => (int) env('SERVER_CRON_RUN_BROADCAST_CHUNK_MS', 120),
        /** Optional Redis queue name; default queue if unset. Horizon must list this queue (see config/horizon.php). */
        'queue' => env('SERVER_CRON_RUN_QUEUE', 'dply'),
    ],

    /*
    | `requires_any_tags` hides the item when the server has none of the listed
    | service tags (see {@see App\Support\Servers\ServerInstalledServices}). Items
    | without the key are always shown. Fails open when the provision stack summary
    | is unavailable so freshly-imported servers still surface everything.
    */
    'nav' => [
        ['key' => 'cluster', 'route' => 'servers.cluster', 'icon' => 'server-stack', 'label' => 'Cluster', 'group' => 'overview', 'only_host_kinds' => ['kubernetes'], 'feature' => 'workspace.cluster'],
        ['key' => 'console', 'route' => 'servers.console', 'preview_route' => 'servers.console-preview', 'icon' => 'command-line', 'label' => 'Console', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.console', 'preview_feature' => 'workspace.console_preview'],
        ['key' => 'overview', 'route' => 'servers.overview', 'icon' => 'cpu-chip', 'label' => 'Overview', 'group' => 'overview', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'run', 'route' => 'servers.run', 'preview_route' => 'servers.run-preview', 'icon' => 'play-circle', 'label' => 'Run', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.run', 'preview_feature' => 'workspace.run_preview'],
        ['key' => 'sites', 'route' => 'servers.sites', 'icon' => 'globe-alt', 'label' => 'Sites', 'group' => 'overview'],
        // monitor — standalone items (Deploys, Errors) lead, then the cluster
        // members: Monitoring (health/metrics/insights/hygiene/shared-host) and
        // Security (certs/patches/security-digest). Cluster reps land at their
        // first listed member, so order here drives sidebar order, not alphabet.
        // Activity was merged into the Logs page as a tab (servers.logs?tab=activity).
        ['key' => 'deploys', 'route' => 'servers.deploys', 'icon' => 'rocket-launch', 'label' => 'Deploys', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'errors', 'route' => 'servers.errors', 'icon' => 'exclamation-triangle', 'label' => 'Errors', 'group' => 'monitor'],
        ['key' => 'health', 'route' => 'servers.health', 'icon' => 'heart', 'label' => 'Health', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.health'],
        ['key' => 'monitor', 'route' => 'servers.monitor', 'icon' => 'chart-bar', 'label' => 'Metrics', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'insights', 'route' => 'servers.insights', 'preview_route' => 'servers.insights', 'icon' => 'light-bulb', 'label' => 'Insights', 'group' => 'monitor', 'feature' => 'workspace.insights', 'preview_feature' => 'workspace.insights_preview'],
        ['key' => 'hygiene', 'route' => 'servers.hygiene', 'preview_route' => 'servers.hygiene', 'icon' => 'archive-box', 'label' => 'Hygiene', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.release_hygiene', 'preview_feature' => 'workspace.release_hygiene_preview'],
        ['key' => 'shared-host', 'route' => 'servers.shared-host', 'preview_route' => 'servers.shared-host', 'icon' => 'signal', 'label' => 'Shared Host', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'requires_min_sites' => 2, 'feature' => 'workspace.shared_host', 'preview_feature' => 'workspace.shared_host_preview'],
        ['key' => 'security-digest', 'route' => 'servers.security-digest', 'preview_route' => 'servers.security-digest', 'icon' => 'shield-exclamation', 'label' => 'Security', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.security_digest', 'preview_feature' => 'workspace.security_digest_preview'],
        ['key' => 'patches', 'route' => 'servers.patches', 'icon' => 'shield-check', 'label' => 'Patches', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.patch_advisor'],
        ['key' => 'cert-inventory', 'route' => 'servers.cert-inventory', 'icon' => 'lock-closed', 'label' => 'Certificates', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.cert_inventory'],
        // stacks — standalone resources (Databases, Caches, Services) lead, then
        // the Runtime (php/configuration/tools) and Web (webserver/edge-proxy/docker) clusters.
        ['key' => 'databases', 'route' => 'servers.databases', 'icon' => 'circle-stack', 'label' => 'Databases', 'group' => 'stacks', 'requires_any_tags' => ['postgres', 'mysql'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'caches', 'route' => 'servers.caches', 'icon' => 'bolt', 'label' => 'Caches', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.caches'],
        ['key' => 'services', 'route' => 'servers.services', 'icon' => 'rectangle-stack', 'label' => 'Services', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.services'],
        ['key' => 'php', 'route' => 'servers.php', 'icon' => 'command-line', 'label' => 'PHP', 'group' => 'stacks', 'requires_any_tags' => ['php'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'configuration', 'route' => 'servers.configuration', 'icon' => 'document-text', 'label' => 'Configuration', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'tools', 'route' => 'servers.tools', 'icon' => 'wrench-screwdriver', 'label' => 'Tools', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'webserver', 'route' => 'servers.webserver', 'icon' => 'globe-alt', 'label' => 'Webserver', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'edge-proxy', 'route' => 'servers.edge-proxy', 'icon' => 'arrow-path-rounded-square', 'label' => 'Edge proxy', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'soon_badge' => true],
        ['key' => 'docker', 'route' => 'servers.docker', 'preview_route' => 'servers.docker', 'icon' => 'square-3-stack-3d', 'label' => 'Docker', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.docker', 'preview_feature' => 'workspace.docker_preview', 'soon_badge' => true],
        // background (alphabetical by label)
        ['key' => 'backups', 'route' => 'servers.backups', 'preview_route' => 'servers.backups', 'icon' => 'archive-box', 'label' => 'Backups', 'group' => 'background', 'requires_any_tags' => ['mysql', 'postgres'], 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.backups', 'preview_feature' => 'workspace.backups_preview'],
        ['key' => 'cron', 'route' => 'servers.cron', 'icon' => 'clock', 'label' => 'Cron jobs', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'schedule', 'route' => 'servers.schedule', 'icon' => 'calendar-days', 'label' => 'Schedule', 'group' => 'background', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.schedule'],
        ['key' => 'snapshots', 'route' => 'servers.snapshots', 'icon' => 'camera', 'label' => 'Snapshots', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'worker-pool', 'route' => 'servers.worker-pool', 'icon' => 'square-3-stack-3d', 'label' => 'Worker Pool', 'group' => 'background', 'except_host_kinds' => ['kubernetes'], 'only_server_roles' => ['worker']],
        ['key' => 'daemons', 'route' => 'servers.workers', 'icon' => 'server-stack', 'label' => 'Workers', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        // access (alphabetical by label)
        ['key' => 'ssh-access', 'route' => 'servers.ssh-access', 'preview_route' => 'servers.ssh-access', 'icon' => 'finger-print', 'label' => 'Access graph', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.ssh_access_graph', 'preview_feature' => 'workspace.ssh_access_graph_preview'],
        ['key' => 'firewall', 'route' => 'servers.firewall', 'icon' => 'shield-check', 'label' => 'Firewall', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'load-balancers', 'route' => 'servers.load-balancers', 'icon' => 'arrows-right-left', 'label' => 'Load balancers', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'soon_badge' => true],
        ['key' => 'networking', 'route' => 'servers.networking', 'icon' => 'share', 'label' => 'Networking', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'ssh', 'route' => 'servers.ssh-keys', 'icon' => 'key', 'label' => 'SSH keys', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'system-users', 'route' => 'servers.system-users', 'icon' => 'user-group', 'label' => 'System users', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.system_users'],
        // admin — standalone (Logs, Files) lead, then the Settings
        // (settings/notifications/maintenance) and Automation (blueprint/cli) clusters.
        // Deploy windows merged into the Deploys page (servers.deploys?tab=deploy-windows).
        // 'manage' nav entry retired: the Manage workspace was dissolved. Tools is
        // now its own Stacks entry (servers.tools); Updates lives on Patches,
        // reboot/stuck-task on Tools, and the host state strip on Overview. The
        // servers.manage route stays registered as a back-compat redirector.
        ['key' => 'logs', 'route' => 'servers.logs', 'icon' => 'clipboard-document-list', 'label' => 'Logs', 'group' => 'admin', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'files', 'route' => 'servers.files', 'preview_route' => 'servers.files', 'icon' => 'folder', 'label' => 'Files', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.files', 'preview_feature' => 'workspace.files_preview'],
        ['key' => 'settings', 'route' => 'servers.settings', 'icon' => 'cog-8-tooth', 'label' => 'Settings', 'group' => 'admin'],
        ['key' => 'notifications', 'route' => 'servers.notifications', 'icon' => 'bell', 'label' => 'Notifications', 'group' => 'admin', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'maintenance', 'route' => 'servers.maintenance', 'preview_route' => 'servers.maintenance', 'icon' => 'wrench', 'label' => 'Maintenance', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_maintenance', 'preview_feature' => 'workspace.server_maintenance_preview'],
        ['key' => 'blueprint', 'route' => 'servers.blueprint', 'preview_route' => 'servers.blueprint', 'icon' => 'document-duplicate', 'label' => 'Blueprint', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_blueprint', 'preview_feature' => 'workspace.server_blueprint_preview'],
        ['key' => 'cli', 'route' => 'servers.cli', 'preview_route' => 'servers.cli-preview', 'icon' => 'command-line', 'label' => 'CLI', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.cli', 'preview_feature' => 'workspace.cli_preview', 'soon_badge' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-role sidebar override
    |--------------------------------------------------------------------------
    | When a server's `server_role` (stored on meta.server_role at create time)
    | appears here, the sidebar is filtered to the listed `keys` and rows may
    | have their `label` / `group` overridden — used to give role-specialised
    | servers (a dedicated Redis/Valkey cache box, not an app server with a
    | co-located cache) a focused nav instead of the full 30+ item generic
    | sidebar built for `application` servers.
    |
    | The route middleware {@see EnsureServerServiceInstalled} mirrors this so
    | deep links to hidden routes 404 — consistent with how tag-gated rows are
    | guarded today.
    |
    | Roles absent from this map (notably `application`, the default) get the
    | full sidebar above unchanged.
    */
    'role_nav_keys' => [
        // Order of `keys` defines the within-group rendering order on these
        // servers — the helper sorts filtered items by their position here so
        // a role can elevate (e.g.) Redis next to Overview without touching
        // the base nav array. `overrides[key].group` re-homes a row into a
        // different group than its base config entry; `overrides[key].label`
        // swaps the displayed label.
        'redis' => [
            'keys' => ['overview', 'caches', 'console', 'health', 'monitor', 'logs', 'snapshots', 'firewall', 'networking', 'ssh', 'cron', 'files', 'tools', 'settings'],
            'overrides' => [
                'caches' => ['label' => 'Redis', 'group' => 'overview'],
                'logs' => ['group' => 'monitor'],
                'cron' => ['group' => 'admin'],
                'snapshots' => ['group' => 'admin'],
            ],
        ],
        'valkey' => [
            'keys' => ['overview', 'caches', 'console', 'health', 'monitor', 'logs', 'snapshots', 'firewall', 'networking', 'ssh', 'cron', 'files', 'tools', 'settings'],
            'overrides' => [
                'caches' => ['label' => 'Valkey', 'group' => 'overview'],
                'logs' => ['group' => 'monitor'],
                'cron' => ['group' => 'admin'],
                'snapshots' => ['group' => 'admin'],
            ],
        ],
        'load_balancer' => [
            'keys' => ['overview', 'load-balancers', 'console', 'health', 'monitor', 'logs', 'firewall', 'networking', 'ssh', 'cron', 'tools', 'settings'],
            'overrides' => [
                'load-balancers' => ['label' => 'Load balancer', 'group' => 'overview'],
                'logs' => ['group' => 'monitor'],
                'cron' => ['group' => 'admin'],
            ],
        ],
        'database' => [
            'keys' => ['overview', 'databases', 'console', 'health', 'monitor', 'logs', 'backups', 'firewall', 'networking', 'load-balancers', 'ssh', 'cron', 'files', 'tools', 'settings'],
            'overrides' => [
                'databases' => ['label' => 'Database', 'group' => 'overview'],
                'logs' => ['group' => 'monitor'],
                'cron' => ['group' => 'admin'],
                'backups' => ['group' => 'admin'],
            ],
        ],
        'worker' => [
            // A worker host runs queue workers + scheduled jobs from deployed
            // code (PHP installed, no webserver/cache/database). Surface Sites
            // (the deployed code) and daemons (the workers) next to Overview;
            // hide the stack tabs that don't apply (databases, caches,
            // webserver, backups, snapshots, load balancers). `services` is
            // included so the systemd inventory (incl. the per-site Horizon
            // unit + queue workers) is reachable on worker hosts.
            'keys' => ['overview', 'sites', 'worker-pool', 'daemons', 'services', 'schedule', 'cron', 'console', 'php', 'health', 'monitor', 'logs', 'firewall', 'networking', 'ssh', 'files', 'tools', 'settings'],
            'overrides' => [
                'daemons' => ['label' => 'Workers', 'group' => 'overview'],
                'worker-pool' => ['group' => 'overview'],
                'schedule' => ['group' => 'background'],
                'cron' => ['group' => 'background'],
                'logs' => ['group' => 'monitor'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nav clusters (one sidebar item → several sub-tab pages)
    |--------------------------------------------------------------------------
    | Collapses several related nav `key`s into a single sidebar item that, when
    | active, renders a secondary tab strip linking to each member's existing
    | route/page. Keeps the sidebar short without merging the underlying Livewire
    | components. Applied only to the default (non-role) sidebar — role navs are
    | already curated short lists.
    |
    | A cluster only collapses when ≥2 of its members survive feature/structural
    | filtering; with 0–1 present the lone member renders as its normal item. The
    | representative lands on the first non-preview member and is highlighted when
    | any member key is the active page. `tab_labels` overrides the sub-tab label
    | (defaults to the member's own nav label).
    */
    'clusters' => [
        'access' => [
            'label' => 'Access',
            'icon' => 'finger-print',
            'members' => ['ssh-access', 'ssh', 'system-users'],
            'tab_labels' => ['ssh-access' => 'Map', 'ssh' => 'SSH keys', 'system-users' => 'System users'],
        ],
        'network' => [
            'label' => 'Network',
            'icon' => 'share',
            'members' => ['firewall', 'networking', 'load-balancers'],
        ],
        'backups' => [
            'label' => 'Backups',
            'icon' => 'archive-box',
            'members' => ['backups', 'snapshots'],
        ],
        'scheduled' => [
            'label' => 'Scheduled tasks',
            'icon' => 'clock',
            'members' => ['cron', 'schedule'],
        ],
        'monitoring' => [
            'label' => 'Monitoring',
            'icon' => 'chart-bar',
            'members' => ['health', 'monitor', 'insights', 'hygiene', 'shared-host'],
            'tab_labels' => ['monitor' => 'Metrics'],
        ],
        'security' => [
            'label' => 'Security',
            'icon' => 'shield-check',
            'members' => ['security-digest', 'patches', 'cert-inventory'],
            'tab_labels' => ['security-digest' => 'Overview', 'cert-inventory' => 'Certificates'],
        ],
        'web' => [
            'label' => 'Web',
            'icon' => 'globe-alt',
            'members' => ['webserver', 'edge-proxy', 'docker'],
        ],
        'runtime' => [
            'label' => 'Runtime',
            'icon' => 'command-line',
            'members' => ['php', 'configuration', 'tools'],
        ],
        'settings' => [
            'label' => 'Settings',
            'icon' => 'cog-8-tooth',
            'members' => ['settings', 'notifications', 'maintenance'],
            'tab_labels' => ['settings' => 'General'],
        ],
        'automation' => [
            'label' => 'Blueprints',
            'icon' => 'document-duplicate',
            'members' => ['blueprint', 'cli'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar group labels
    |--------------------------------------------------------------------------
    | Display labels for the group keys above. Only groups actually present in
    | the rendered nav show a heading — when filtering by installed-service tags
    | hides every item in a group, the heading is skipped too.
    */
    'nav_groups' => [
        'overview' => 'Overview',
        'monitor' => 'Monitor',
        'stacks' => 'Stacks',
        'background' => 'Background',
        'access' => 'Access',
        'admin' => 'Admin',
    ],

    /*
    | Webserver engines listed here show "Coming soon" in the workspace UI
    | (engine tabs + switch picker) and cannot be switched to until removed.
    | Tests override with config(['server_workspace.webserver_coming_soon' => []]).
    */
    // Workspace pages that render the shared rich coming-soon teaser
    // (<x-workspace-coming-soon>) instead of their (not-yet-finished) real
    // content. Pages with a dedicated *-preview-panel + Pennant preview flag
    // (docker, ssh-access, files, …) handle their own teaser via that flag.
    'coming_soon_keys' => ['load-balancers', 'edge-proxy'],

    'webserver_coming_soon' => ['apache', 'openlitespeed'],

    /*
    | Edge proxy engines listed here show "Coming soon" in the overview picker
    | and render preview tabs until removed. Active installs keep full controls.
    */
    'edge_proxy_coming_soon' => ['traefik', 'haproxy', 'envoy', 'openresty'],
];
