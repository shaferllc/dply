<?php

use App\Models\SiteDeployStep;

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
     * One-click shell hook shortcuts (opens configure modal with script prefilled).
     *
     * @var list<array{kind: string, label: string, icon: string, anchor?: string, script: string, requires?: string}>
     */
    'hook_presets' => [
        [
            'kind' => 'shell',
            'label' => 'Maintenance down',
            'icon' => 'heroicon-o-pause-circle',
            'anchor' => 'before_activate',
            'script' => "php artisan down\n",
            'requires' => 'laravel',
        ],
        [
            'kind' => 'shell',
            'label' => 'Maintenance up',
            'icon' => 'heroicon-o-play-circle',
            'anchor' => 'after_activate',
            'script' => "php artisan up\n",
            'requires' => 'laravel',
        ],
        [
            'kind' => 'shell',
            'label' => 'HTTP health check',
            'icon' => 'heroicon-o-signal',
            'anchor' => 'after_activate',
            'script' => "curl -fsS -o /dev/null \"\${APP_URL:-http://127.0.0.1}\" || exit 1\n",
        ],
        [
            'kind' => 'shell',
            'label' => 'Horizon terminate',
            'icon' => 'heroicon-o-arrow-path',
            'anchor' => 'after_activate',
            'script' => "php artisan horizon:terminate\n",
            'requires' => 'laravel',
        ],
        [
            'kind' => 'shell',
            'label' => 'Notify Slack (curl)',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'anchor' => 'after_activate',
            'script' => "curl -X POST -H 'Content-type: application/json' --data '{\"text\":\"Deploy finished\"}' \"\$SLACK_WEBHOOK_URL\"\n",
        ],
    ],
    /**
     * Draggable palette entries (add to pipeline on drop or click).
     * Optional `custom_command` for npm_run / custom presets.
     * Optional `requires`: laravel, rails, node, php, ruby — hidden when not applicable.
     *
     * @var list<array{type: string, label: string, icon: string, phase: string, custom_command?: string, requires?: string}>
     */
    'palette' => [
        // Dependencies — build
        ['type' => SiteDeployStep::TYPE_COMPOSER_INSTALL, 'label' => 'Composer install', 'icon' => 'heroicon-o-cube', 'phase' => 'build', 'requires' => 'php'],
        ['type' => SiteDeployStep::TYPE_NPM_CI, 'label' => 'npm ci', 'icon' => 'heroicon-o-command-line', 'phase' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_NPM_INSTALL, 'label' => 'npm install', 'icon' => 'heroicon-o-command-line', 'phase' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_YARN_INSTALL, 'label' => 'yarn install', 'icon' => 'heroicon-o-command-line', 'phase' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_PNPM_INSTALL, 'label' => 'pnpm install', 'icon' => 'heroicon-o-command-line', 'phase' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_BUN_INSTALL, 'label' => 'bun install', 'icon' => 'heroicon-o-command-line', 'phase' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_NPM_RUN, 'label' => 'npm run build', 'icon' => 'heroicon-o-play', 'phase' => 'build', 'custom_command' => 'build', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_NPM_RUN, 'label' => 'npm run dev', 'icon' => 'heroicon-o-play', 'phase' => 'build', 'custom_command' => 'dev', 'requires' => 'node'],
        ['type' => SiteDeployStep::TYPE_NPM_RUN, 'label' => 'npm run production', 'icon' => 'heroicon-o-play', 'phase' => 'build', 'custom_command' => 'production', 'requires' => 'node'],
        // Laravel — build
        ['type' => SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE, 'label' => 'Config cache', 'icon' => 'heroicon-o-cog-6-tooth', 'phase' => 'build', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE, 'label' => 'Route cache', 'icon' => 'heroicon-o-map', 'phase' => 'build', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE, 'label' => 'View cache', 'icon' => 'heroicon-o-eye', 'phase' => 'build', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_EVENT_CACHE, 'label' => 'Event cache', 'icon' => 'heroicon-o-bolt', 'phase' => 'build', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL, 'label' => 'Octane install', 'icon' => 'heroicon-o-rocket-launch', 'phase' => 'build', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL, 'label' => 'Reverb install', 'icon' => 'heroicon-o-signal', 'phase' => 'build', 'requires' => 'laravel'],
        // Rails — build
        ['type' => SiteDeployStep::TYPE_CUSTOM, 'label' => 'bundle install', 'icon' => 'heroicon-o-cube', 'phase' => 'build', 'custom_command' => 'bundle install --deployment --without development:test', 'requires' => 'rails'],
        ['type' => SiteDeployStep::TYPE_CUSTOM, 'label' => 'assets:precompile', 'icon' => 'heroicon-o-photo', 'phase' => 'build', 'custom_command' => 'bundle exec rails assets:precompile', 'requires' => 'rails'],
        // Release — Laravel
        ['type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND, 'label' => 'Migrate (pretend)', 'icon' => 'heroicon-o-eye', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE, 'label' => 'Migrate', 'icon' => 'heroicon-o-arrow-path', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_DB_SEED, 'label' => 'DB seed', 'icon' => 'heroicon-o-circle-stack', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, 'label' => 'Optimize', 'icon' => 'heroicon-o-bolt', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_STORAGE_LINK, 'label' => 'Storage link', 'icon' => 'heroicon-o-link', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART, 'label' => 'Queue restart', 'icon' => 'heroicon-o-arrow-path', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_HORIZON_TERMINATE, 'label' => 'Horizon terminate', 'icon' => 'heroicon-o-stop', 'phase' => 'release', 'requires' => 'laravel'],
        ['type' => SiteDeployStep::TYPE_ARTISAN_CACHE_CLEAR, 'label' => 'Cache clear', 'icon' => 'heroicon-o-trash', 'phase' => 'release', 'requires' => 'laravel'],
        // Release — Rails
        ['type' => SiteDeployStep::TYPE_CUSTOM, 'label' => 'rails db:migrate', 'icon' => 'heroicon-o-arrow-path', 'phase' => 'release', 'custom_command' => 'bundle exec rails db:migrate', 'requires' => 'rails'],
        // Generic
        ['type' => SiteDeployStep::TYPE_CUSTOM, 'label' => 'Custom command', 'icon' => 'heroicon-o-code-bracket', 'phase' => 'build'],
        ['type' => SiteDeployStep::TYPE_CUSTOM, 'label' => 'Custom (release)', 'icon' => 'heroicon-o-code-bracket', 'phase' => 'release'],
    ],
    /**
     * Labels for grouping entries in the full step catalog (Reference tab).
     *
     * @var array<string, array{label: string, description: string, order: int}>
     */
    'catalog_groups' => [
        'generic' => [
            'label' => 'Generic',
            'description' => 'Custom shell commands for any stack.',
            'order' => 10,
        ],
        'generic_release' => [
            'label' => 'Generic (release)',
            'description' => 'Custom commands that run after activate on the live path.',
            'order' => 20,
        ],
        'php_build' => [
            'label' => 'PHP / Composer',
            'description' => 'PHP dependency install in the new release directory.',
            'order' => 30,
        ],
        'node_build' => [
            'label' => 'Node / front-end',
            'description' => 'JavaScript package installs and npm scripts.',
            'order' => 40,
        ],
        'laravel_build' => [
            'label' => 'Laravel (build)',
            'description' => 'Compile caches and one-shot scaffolding before traffic switches.',
            'order' => 50,
        ],
        'laravel_release' => [
            'label' => 'Laravel (release)',
            'description' => 'Database, caches, and workers on the live release after activate.',
            'order' => 60,
        ],
        'rails_build' => [
            'label' => 'Rails (build)',
            'description' => 'Bundler and asset pipeline in the new release.',
            'order' => 70,
        ],
        'rails_release' => [
            'label' => 'Rails (release)',
            'description' => 'Database migrations on the live path.',
            'order' => 80,
        ],
    ],
];
