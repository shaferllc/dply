<?php

use App\Models\SiteDeployStep;

return [
    /**
     * Full starter pipelines (Rollout + steps + hooks). Filtered via requires + DeployPipelinePalette::entryVisible().
     *
     * @var array<string, array{
     *     label: string,
     *     description: string,
     *     icon: string,
     *     order: int,
     *     requires?: string,
     *     strategy: string,
     *     steps_from?: string,
     *     steps?: list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>,
     *     include_safety_bundle?: bool,
     *     new_pipeline_name?: string,
     * }>
     */
    'starters' => [
        'simple-in-place' => [
            'label' => 'Simple deploy',
            'description' => 'In-place Git deploy: dependencies in the new checkout, schema commands in Build (live path updates immediately).',
            'icon' => 'heroicon-o-arrow-down-tray',
            'order' => 10,
            'strategy' => 'simple',
            'steps_from' => 'runtime',
            'new_pipeline_name' => 'Simple deploy',
        ],
        'zero-downtime' => [
            'label' => 'Zero downtime',
            'description' => 'Atomic releases with symlink swap, post-activate health check, and release-phase migrations when applicable.',
            'icon' => 'heroicon-o-arrows-right-left',
            'order' => 20,
            'strategy' => 'atomic',
            'steps_from' => 'runtime',
            'new_pipeline_name' => 'Zero downtime',
        ],
        'laravel-simple' => [
            'label' => 'Laravel · simple',
            'description' => 'Composer install and migrate on the live checkout—best for staging or small apps.',
            'icon' => 'heroicon-o-cube',
            'order' => 30,
            'requires' => 'laravel',
            'strategy' => 'simple',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 600,
                ],
            ],
            'new_pipeline_name' => 'Laravel simple',
        ],
        'laravel-zero-downtime' => [
            'label' => 'Laravel · zero downtime',
            'description' => 'Atomic deploy with Composer in Build, migrate and optimize after activate.',
            'icon' => 'heroicon-o-arrows-right-left',
            'order' => 40,
            'requires' => 'laravel',
            'strategy' => 'atomic',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 120,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 120,
                ],
            ],
            'new_pipeline_name' => 'Laravel zero downtime',
        ],
        'laravel-zero-downtime-safe' => [
            'label' => 'Laravel · zero downtime (safe)',
            'description' => 'Zero downtime plus maintenance mode, migrate pretend, and a pre-migrate DB snapshot.',
            'icon' => 'heroicon-o-shield-check',
            'order' => 50,
            'requires' => 'laravel',
            'strategy' => 'atomic',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 120,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'timeout_seconds' => 120,
                ],
            ],
            'include_safety_bundle' => true,
            'new_pipeline_name' => 'Laravel zero downtime (safe)',
        ],
        'node-ssr-simple' => [
            'label' => 'Node SSR · simple',
            'description' => 'npm ci and production build on the live checkout.',
            'icon' => 'heroicon-o-command-line',
            'order' => 60,
            'requires' => 'node',
            'strategy' => 'simple',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_CI,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 900,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_RUN,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'build',
                    'timeout_seconds' => 900,
                ],
            ],
            'new_pipeline_name' => 'Node SSR simple',
        ],
        'node-ssr-zero-downtime' => [
            'label' => 'Node SSR · zero downtime',
            'description' => 'Atomic release with npm ci and build before symlink swap.',
            'icon' => 'heroicon-o-arrows-right-left',
            'order' => 70,
            'requires' => 'node',
            'strategy' => 'atomic',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_CI,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 900,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_RUN,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'build',
                    'timeout_seconds' => 900,
                ],
            ],
            'new_pipeline_name' => 'Node SSR zero downtime',
        ],
        'rails-simple' => [
            'label' => 'Rails · simple',
            'description' => 'Bundle install, asset precompile, and db:migrate on the live checkout.',
            'icon' => 'heroicon-o-cube',
            'order' => 80,
            'requires' => 'rails',
            'strategy' => 'simple',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'bundle install --deployment --without development:test',
                    'timeout_seconds' => 900,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'bundle exec rails assets:precompile',
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'bundle exec rails db:migrate',
                    'timeout_seconds' => 600,
                ],
            ],
            'new_pipeline_name' => 'Rails simple',
        ],
        'rails-zero-downtime' => [
            'label' => 'Rails · zero downtime',
            'description' => 'Atomic release: assets in Build, db:migrate after activate.',
            'icon' => 'heroicon-o-arrows-right-left',
            'order' => 90,
            'requires' => 'rails',
            'strategy' => 'atomic',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'bundle install --deployment --without development:test',
                    'timeout_seconds' => 900,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'bundle exec rails assets:precompile',
                    'timeout_seconds' => 600,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_CUSTOM,
                    'phase' => SiteDeployStep::PHASE_RELEASE,
                    'custom_command' => 'bundle exec rails db:migrate',
                    'timeout_seconds' => 600,
                ],
            ],
            'new_pipeline_name' => 'Rails zero downtime',
        ],
        'static-zero-downtime' => [
            'label' => 'Static site · zero downtime',
            'description' => 'Atomic release with npm ci and static build—no release-phase steps.',
            'icon' => 'heroicon-o-photo',
            'order' => 100,
            'requires' => 'static',
            'strategy' => 'atomic',
            'steps' => [
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_CI,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'timeout_seconds' => 900,
                ],
                [
                    'step_type' => SiteDeployStep::TYPE_NPM_RUN,
                    'phase' => SiteDeployStep::PHASE_BUILD,
                    'custom_command' => 'build',
                    'timeout_seconds' => 900,
                ],
            ],
            'new_pipeline_name' => 'Static zero downtime',
        ],
    ],
];
