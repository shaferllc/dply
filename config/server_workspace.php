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

    'nav' => [
        ['key' => 'sites', 'route' => 'servers.sites', 'icon' => 'globe-alt', 'label' => 'Sites'],
        ['key' => 'deploy', 'route' => 'servers.deploy', 'icon' => 'rocket-launch', 'label' => 'Deploy'],
        ['key' => 'overview', 'route' => 'servers.overview', 'icon' => 'cpu-chip', 'label' => 'Overview'],
        ['key' => 'insights', 'route' => 'servers.insights', 'icon' => 'light-bulb', 'label' => 'Insights'],
        ['key' => 'monitor', 'route' => 'servers.monitor', 'icon' => 'chart-bar', 'label' => 'Metrics'],
        ['key' => 'services', 'route' => 'servers.services', 'icon' => 'rectangle-stack', 'label' => 'Services'],
        ['key' => 'php', 'route' => 'servers.php', 'icon' => 'command-line', 'label' => 'PHP'],
        ['key' => 'databases', 'route' => 'servers.databases', 'icon' => 'circle-stack', 'label' => 'Databases'],
        ['key' => 'cron', 'route' => 'servers.cron', 'icon' => 'clock', 'label' => 'Cron jobs'],
        ['key' => 'daemons', 'route' => 'servers.daemons', 'icon' => 'server-stack', 'label' => 'Daemons'],
        ['key' => 'firewall', 'route' => 'servers.firewall', 'icon' => 'shield-check', 'label' => 'Firewall'],
        ['key' => 'ssh', 'route' => 'servers.ssh-keys', 'icon' => 'key', 'label' => 'SSH keys'],
        ['key' => 'recipes', 'route' => 'servers.recipes', 'icon' => 'document-text', 'label' => 'Saved commands'],
        ['key' => 'logs', 'route' => 'servers.logs', 'icon' => 'clipboard-document-list', 'label' => 'Logs'],
        ['key' => 'manage', 'route' => 'servers.manage', 'icon' => 'wrench-screwdriver', 'label' => 'Manage'],
        ['key' => 'settings', 'route' => 'servers.settings', 'icon' => 'cog-8-tooth', 'label' => 'Settings'],
    ],

];
