<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\SiteDeployStep;

/**
 * Canonical default SiteDeployStep set for each runtime / framework.
 *
 * Per the strategy memo: "Build/release have runtime-aware defaults,
 * user-editable in dashboard." This service is the single source of
 * truth — site-create calls it to pre-populate the deploy pipeline,
 * the deploy-step UI's "reset to defaults" affordance reads from
 * the same map, and tests can assert against the same shape.
 *
 * Each entry describes one SiteDeployStep row:
 *   step_type       — one of SiteDeployStep::TYPE_*
 *   phase           — SiteDeployStep::PHASE_BUILD or PHASE_RELEASE
 *                    (the dply-owned PHASE_SWAP and PHASE_RESTART
 *                    don't carry user steps)
 *   custom_command  — only set when step_type=TYPE_CUSTOM
 *   timeout_seconds — runtime-appropriate (longer for asset builds
 *                    and Ruby/Python source-builds)
 *
 * `sort_order` is assigned at materialization time as the array
 * index — callers shouldn't try to reorder this list independently
 * of the per-runtime intent (e.g. composer-install must precede
 * artisan-migrate).
 */
class RuntimeAwareDeployStepDefaults
{
    /**
     * Returns the canonical default step set for a runtime + framework.
     *
     * Both arguments are optional: a null runtime returns an empty list
     * (caller picks defaults at site-create time when nothing has been
     * detected); an unknown framework falls through to the runtime's
     * generic defaults.
     *
     * @return list<array{
     *     step_type: string,
     *     phase: string,
     *     custom_command?: string,
     *     timeout_seconds: int,
     *     sort_order: int,
     * }>
     */
    public function defaultsFor(?string $runtime, ?string $framework = null): array
    {
        $steps = match ($runtime) {
            'php' => $this->phpSteps($framework),
            'node' => $this->nodeSteps($framework),
            'python' => $this->pythonSteps($framework),
            'ruby' => $this->rubySteps($framework),
            'go' => $this->goSteps(),
            'static' => $this->staticSteps($framework),
            default => [],
        };

        // Stamp sort_order in declaration order so callers don't have to.
        foreach ($steps as $i => $_) {
            $steps[$i]['sort_order'] = ($i + 1) * 10;
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phpSteps(?string $framework): array
    {
        $steps = [
            [
                'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 600,
            ],
        ];

        if ($framework === 'laravel') {
            // Laravel ships with a Vite frontend by default — build the assets so
            // `@vite(...)` finds public/build/manifest.json (otherwise the app
            // 500s with ViteManifestNotFoundException). These no-op gracefully on
            // an API-only app with no package.json (see ensureToolingPrefix), and
            // are user-removable like any default step. Node is self-healed via
            // mise at deploy time since the base box ships without it.
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_NPM_CI,
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ];
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_NPM_RUN,
                'custom_command' => 'build',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ];
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
                'phase' => SiteDeployStep::PHASE_RELEASE,
                'timeout_seconds' => 300,
            ];
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
                'phase' => SiteDeployStep::PHASE_RELEASE,
                'timeout_seconds' => 120,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodeSteps(?string $framework): array
    {
        $steps = [
            [
                'step_type' => SiteDeployStep::TYPE_NPM_CI,
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ],
        ];

        // Frameworks that ship with a `build` script (next, nuxt, sveltekit,
        // remix, astro, nest) need an explicit build step. The runtime
        // detector keys we use here match what NodeRuntimeDetector emits.
        if (in_array($framework, ['next', 'nuxt', 'sveltekit', 'remix', 'astro', 'nest'], true)) {
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_NPM_RUN,
                'custom_command' => 'build',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pythonSteps(?string $framework): array
    {
        $steps = [
            [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'pip install -r requirements.txt',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ],
        ];

        if ($framework === 'django') {
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'python manage.py collectstatic --noinput',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 300,
            ];
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'python manage.py migrate --noinput',
                'phase' => SiteDeployStep::PHASE_RELEASE,
                'timeout_seconds' => 600,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rubySteps(?string $framework): array
    {
        $steps = [
            [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'bundle install --deployment --without development:test',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ],
        ];

        if ($framework === 'rails') {
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'bundle exec rails assets:precompile',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 600,
            ];
            $steps[] = [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'bundle exec rails db:migrate',
                'phase' => SiteDeployStep::PHASE_RELEASE,
                'timeout_seconds' => 600,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function goSteps(): array
    {
        // Go's idiomatic deploy is build-once via `go build`. No release-
        // phase steps by default — DB migrations etc. go through a
        // user-added custom command since there's no canonical Go ORM.
        return [
            [
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'go build -o bin/app ./...',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 900,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function staticSteps(?string $framework): array
    {
        return match ($framework) {
            'jekyll' => [[
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'bundle exec jekyll build',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 600,
            ]],
            'hugo' => [[
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'hugo --minify',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 300,
            ]],
            'eleventy' => [[
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => 'npx @11ty/eleventy',
                'phase' => SiteDeployStep::PHASE_BUILD,
                'timeout_seconds' => 600,
            ]],
            default => [],
        };
    }
}
