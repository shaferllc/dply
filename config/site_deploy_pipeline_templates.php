<?php

use App\Models\SiteDeployStep;

return [
    'templates' => [
        'laravel' => [
            'label' => 'Laravel',
            'description' => 'Composer install, migrate, and optimize after activate.',
            'runtime' => 'php',
            'framework' => 'laravel',
        ],
        'nodejs-ssr' => [
            'label' => 'Node (SSR build)',
            'description' => 'npm ci and production build for Next, Nuxt, Remix, and similar.',
            'runtime' => 'node',
            'framework' => 'next',
        ],
        'static-site' => [
            'label' => 'Static site',
            'description' => 'Install dependencies and run a static export/build script.',
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
        ],
        'ruby-rails' => [
            'label' => 'Ruby / Rails',
            'description' => 'Bundle install and asset precompile.',
            'runtime' => 'ruby',
            'framework' => 'rails',
        ],
    ],
];
