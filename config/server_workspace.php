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
        ['key' => 'run', 'route' => 'servers.run', 'icon' => 'play-circle', 'label' => 'Run', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.run'],
        ['key' => 'console', 'route' => 'servers.console', 'icon' => 'command-line', 'label' => 'Console', 'group' => 'overview', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.console'],
        ['key' => 'health', 'route' => 'servers.health', 'icon' => 'heart', 'label' => 'Health', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.health'],
        ['key' => 'blueprint', 'route' => 'servers.blueprint', 'icon' => 'document-duplicate', 'label' => 'Blueprint', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_blueprint'],
        ['key' => 'maintenance', 'route' => 'servers.maintenance', 'icon' => 'wrench', 'label' => 'Maintenance', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.server_maintenance'],
        ['key' => 'insights', 'route' => 'servers.insights', 'icon' => 'light-bulb', 'label' => 'Insights', 'group' => 'monitor', 'feature' => 'workspace.insights'],
        ['key' => 'monitor', 'route' => 'servers.monitor', 'icon' => 'chart-bar', 'label' => 'Metrics', 'group' => 'monitor', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'activity', 'route' => 'servers.activity', 'icon' => 'clipboard-document-list', 'label' => 'Activity', 'group' => 'monitor', 'feature' => 'workspace.activity'],
        ['key' => 'caches', 'route' => 'servers.caches', 'icon' => 'bolt', 'label' => 'Caches', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.caches'],
        ['key' => 'databases', 'route' => 'servers.databases', 'icon' => 'circle-stack', 'label' => 'Databases', 'group' => 'stacks', 'requires_any_tags' => ['postgres', 'mysql'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'php', 'route' => 'servers.php', 'icon' => 'command-line', 'label' => 'PHP', 'group' => 'stacks', 'requires_any_tags' => ['php'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'services', 'route' => 'servers.services', 'icon' => 'rectangle-stack', 'label' => 'Services', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.services'],
        ['key' => 'webserver', 'route' => 'servers.webserver', 'icon' => 'globe-alt', 'label' => 'Webserver', 'group' => 'stacks', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'cron', 'route' => 'servers.cron', 'icon' => 'clock', 'label' => 'Cron jobs', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'schedule', 'route' => 'servers.schedule', 'icon' => 'calendar-days', 'label' => 'Schedule', 'group' => 'background', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.schedule'],
        ['key' => 'daemons', 'route' => 'servers.daemons', 'icon' => 'server-stack', 'label' => 'Daemons', 'group' => 'background', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'backups', 'route' => 'servers.backups', 'icon' => 'archive-box', 'label' => 'Backups', 'group' => 'background', 'requires_any_tags' => ['mysql', 'postgres'], 'except_host_kinds' => ['kubernetes']],
        ['key' => 'firewall', 'route' => 'servers.firewall', 'icon' => 'shield-check', 'label' => 'Firewall', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'ssh', 'route' => 'servers.ssh-keys', 'icon' => 'key', 'label' => 'SSH keys', 'group' => 'access', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'system-users', 'route' => 'servers.system-users', 'icon' => 'user-group', 'label' => 'System users', 'group' => 'access', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.system_users'],
        ['key' => 'logs', 'route' => 'servers.logs', 'icon' => 'clipboard-document-list', 'label' => 'Logs', 'group' => 'admin', 'except_host_kinds' => ['kubernetes']],
        ['key' => 'files', 'route' => 'servers.files', 'icon' => 'folder', 'label' => 'Files', 'group' => 'admin', 'except_host_kinds' => ['kubernetes'], 'feature' => 'workspace.files'],
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

];
