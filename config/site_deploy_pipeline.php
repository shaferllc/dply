<?php

return [
    'tabs' => [
        'overview' => 'Overview',
        'steps' => 'Pipeline',
        'rollout' => 'Rollout',
        'reference' => 'Reference',
    ],
    'tab_icons' => [
        'overview' => 'heroicon-o-squares-2x2',
        'steps' => 'heroicon-o-wrench-screwdriver',
        'rollout' => 'heroicon-o-arrows-right-left',
        'reference' => 'heroicon-o-book-open',
    ],
    /**
     * Draggable hook kinds — drop zone on the timeline sets when the hook runs.
     *
     * @var list<array{kind: string, label: string, icon: string}>
     */
    'hook_palette' => [
        ['kind' => 'shell', 'label' => 'Shell', 'icon' => 'heroicon-o-bolt'],
        ['kind' => 'webhook', 'label' => 'Webhook', 'icon' => 'heroicon-o-globe-alt'],
        ['kind' => 'notification', 'label' => 'Notification', 'icon' => 'heroicon-o-bell-alert'],
    ],
    /**
     * Draggable palette entries (add to pipeline on drop).
     *
     * @var list<array{type: string, label: string, icon: string, phase: string}>
     */
    'palette' => [
        ['type' => 'composer_install', 'label' => 'Composer install', 'icon' => 'heroicon-o-cube', 'phase' => 'build'],
        ['type' => 'npm_ci', 'label' => 'npm ci', 'icon' => 'heroicon-o-command-line', 'phase' => 'build'],
        ['type' => 'npm_install', 'label' => 'npm install', 'icon' => 'heroicon-o-command-line', 'phase' => 'build'],
        ['type' => 'npm_run', 'label' => 'npm run script', 'icon' => 'heroicon-o-play', 'phase' => 'build'],
        ['type' => 'artisan_config_cache', 'label' => 'Config cache', 'icon' => 'heroicon-o-cog-6-tooth', 'phase' => 'build'],
        ['type' => 'artisan_migrate', 'label' => 'Migrate', 'icon' => 'heroicon-o-arrow-path', 'phase' => 'release'],
        ['type' => 'artisan_optimize', 'label' => 'Optimize', 'icon' => 'heroicon-o-bolt', 'phase' => 'release'],
        ['type' => 'custom', 'label' => 'Custom command', 'icon' => 'heroicon-o-code-bracket', 'phase' => 'build'],
    ],
];
