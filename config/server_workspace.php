<?php

/**
 * Server workspace sidebar: `key` matches :active on x-server-workspace-shell.
 */
return [

    /**
     * Queued “Run now” for cron jobs: SSH runs in a worker; output streams via Reverb (and cache for poll fallback).
     */
    'cron_run' => [
        'cache_ttl_seconds' => (int) env('SERVER_CRON_RUN_CACHE_TTL', 900),
        'broadcast_chunk_interval_ms' => (int) env('SERVER_CRON_RUN_BROADCAST_CHUNK_MS', 120),
        /** Optional Redis queue name; default queue if unset. Horizon must list this queue (see config/horizon.php). */
        'queue' => env('SERVER_CRON_RUN_QUEUE'),
    ],

    /*
    | `requires_any_tags` hides the item when the server has none of the listed
    | service tags (see {@see App\Support\Servers\ServerInstalledServices}). Items
    | without the key are always shown. Fails open when the provision stack summary
    | is unavailable so freshly-imported servers still surface everything.
    */
    'nav' => [
        // Overview leads — operators arriving at the workspace want
        // the at-a-glance dashboard first, then drill into Sites
        // from there. The "Deploy" entry was removed because it
        // /run is the merged surface for executing things on this
        // server: saved commands (recipes), ad-hoc shell, marketplace
        // imports. Replaces both /deploy (deleted, was misleadingly
        // named for what was server-level admin) and /recipes
        // (renamed). It sits high in the nav because operators who
        // know their server is healthy come here next to do something.
        // 'group' clusters items under a small uppercase heading in the sidebar.
        // Items keep the original flat order — the render walks them once and
        // emits a heading whenever the group changes from the previous item.
        // Groups (in order): overview | monitor | stacks | background | access | admin.
        ['key' => 'cluster', 'route' => 'servers.cluster', 'icon' => 'server-stack', 'label' => 'Cluster', 'group' => 'overview', 'only_host_kinds' => ['kubernetes'], 'feature' => 'workspace.cluster'],
        ['key' => 'overview', 'route' => 'servers.overview', 'icon' => 'cpu-chip', 'label' => 'Overview', 'group' => 'overview', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'sites', 'route' => 'servers.sites', 'icon' => 'globe-alt', 'label' => 'Sites', 'group' => 'overview'],
        ['key' => 'run', 'route' => 'servers.run', 'preview_route' => 'servers.run-preview', 'icon' => 'play-circle', 'label' => 'Run', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.run', 'preview_feature' => 'workspace.run_preview'],
        ['key' => 'console', 'route' => 'servers.console', 'preview_route' => 'servers.console-preview', 'icon' => 'command-line', 'label' => 'Console', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.console', 'preview_feature' => 'workspace.console_preview'],
        ['key' => 'health', 'route' => 'servers.health', 'icon' => 'heart', 'label' => 'Health', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.health'],
        ['key' => 'shared-host', 'route' => 'servers.shared-host', 'preview_route' => 'servers.shared-host', 'icon' => 'signal', 'label' => 'Shared Host', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'requires_min_sites' => 2, 'feature' => 'workspace.shared_host', 'preview_feature' => 'workspace.shared_host_preview'],
        ['key' => 'patches', 'route' => 'servers.patches', 'icon' => 'shield-check', 'label' => 'Patches', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.patch_advisor'],
        ['key' => 'hygiene', 'route' => 'servers.hygiene', 'preview_route' => 'servers.hygiene', 'icon' => 'archive-box', 'label' => 'Hygiene', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.release_hygiene', 'preview_feature' => 'workspace.release_hygiene_preview'],
        ['key' => 'cert-inventory', 'route' => 'servers.cert-inventory', 'icon' => 'lock-closed', 'label' => 'Certificates', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.cert_inventory'],
        ['key' => 'security-digest', 'route' => 'servers.security-digest', 'preview_route' => 'servers.security-digest', 'icon' => 'shield-exclamation', 'label' => 'Security', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.security_digest', 'preview_feature' => 'workspace.security_digest_preview'],
        ['key' => 'blueprint', 'route' => 'servers.blueprint', 'preview_route' => 'servers.blueprint', 'icon' => 'document-duplicate', 'label' => 'Blueprint', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_blueprint', 'preview_feature' => 'workspace.server_blueprint_preview'],
        ['key' => 'maintenance', 'route' => 'servers.maintenance', 'preview_route' => 'servers.maintenance', 'icon' => 'wrench', 'label' => 'Maintenance', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_maintenance', 'preview_feature' => 'workspace.server_maintenance_preview'],
        ['key' => 'deploy-policy', 'route' => 'servers.deploy-policy', 'preview_route' => 'servers.deploy-policy', 'icon' => 'calendar-days', 'label' => 'Deploy windows', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.deploy_windows', 'preview_feature' => 'workspace.deploy_windows_preview'],
        ['key' => 'insights', 'route' => 'servers.insights', 'preview_route' => 'servers.insights', 'icon' => 'light-bulb', 'label' => 'Insights', 'group' => 'monitor', 'feature' => 'workspace.insights', 'preview_feature' => 'workspace.insights_preview'],
        ['key' => 'monitor', 'route' => 'servers.monitor', 'icon' => 'chart-bar', 'label' => 'Metrics', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'activity', 'route' => 'servers.activity', 'icon' => 'clipboard-document-list', 'label' => 'Activity', 'group' => 'monitor', 'feature' => 'workspace.activity'],
        ['key' => 'caches', 'route' => 'servers.caches', 'icon' => 'bolt', 'label' => 'Caches', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.caches'],
        ['key' => 'docker', 'route' => 'servers.docker', 'preview_route' => 'servers.docker', 'icon' => 'square-3-stack-3d', 'label' => 'Docker', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.docker', 'preview_feature' => 'workspace.docker_preview'],
        ['key' => 'databases', 'route' => 'servers.databases', 'icon' => 'circle-stack', 'label' => 'Databases', 'group' => 'stacks', 'requires_any_tags' => ['postgres', 'mysql'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'php', 'route' => 'servers.php', 'icon' => 'command-line', 'label' => 'PHP', 'group' => 'stacks', 'requires_any_tags' => ['php'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'services', 'route' => 'servers.services', 'icon' => 'rectangle-stack', 'label' => 'Services', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.services'],
        ['key' => 'webserver', 'route' => 'servers.webserver', 'icon' => 'globe-alt', 'label' => 'Webserver', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'edge-proxy', 'route' => 'servers.edge-proxy', 'icon' => 'arrow-path-rounded-square', 'label' => 'Edge proxy', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'configuration', 'route' => 'servers.configuration', 'icon' => 'document-text', 'label' => 'Configuration', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'cron', 'route' => 'servers.cron', 'icon' => 'clock', 'label' => 'Cron jobs', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'schedule', 'route' => 'servers.schedule', 'icon' => 'calendar-days', 'label' => 'Schedule', 'group' => 'background', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.schedule'],
        ['key' => 'daemons', 'route' => 'servers.daemons', 'icon' => 'server-stack', 'label' => 'Daemons', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'backups', 'route' => 'servers.backups', 'preview_route' => 'servers.backups', 'icon' => 'archive-box', 'label' => 'Backups', 'group' => 'background', 'requires_any_tags' => ['mysql', 'postgres'], 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.backups', 'preview_feature' => 'workspace.backups_preview'],
        ['key' => 'firewall', 'route' => 'servers.firewall', 'icon' => 'shield-check', 'label' => 'Firewall', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'ssh', 'route' => 'servers.ssh-keys', 'icon' => 'key', 'label' => 'SSH keys', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'ssh-access', 'route' => 'servers.ssh-access', 'preview_route' => 'servers.ssh-access', 'icon' => 'finger-print', 'label' => 'Access graph', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.ssh_access_graph', 'preview_feature' => 'workspace.ssh_access_graph_preview'],
        ['key' => 'system-users', 'route' => 'servers.system-users', 'icon' => 'user-group', 'label' => 'System users', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.system_users'],
        ['key' => 'logs', 'route' => 'servers.logs', 'icon' => 'clipboard-document-list', 'label' => 'Logs', 'group' => 'admin', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'files', 'route' => 'servers.files', 'preview_route' => 'servers.files', 'icon' => 'folder', 'label' => 'Files', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.files', 'preview_feature' => 'workspace.files_preview'],
        ['key' => 'manage', 'route' => 'servers.manage', 'icon' => 'wrench-screwdriver', 'label' => 'Manage', 'group' => 'admin', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'settings', 'route' => 'servers.settings', 'icon' => 'cog-8-tooth', 'label' => 'Settings', 'group' => 'admin'],
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
    'webserver_coming_soon' => ['caddy', 'apache', 'openlitespeed'],

    /*
    | Edge proxy engines listed here show "Coming soon" in the overview picker
    | and render preview tabs until removed. Active installs keep full controls.
    */
    'edge_proxy_coming_soon' => ['traefik', 'haproxy', 'envoy', 'openresty'],
];
