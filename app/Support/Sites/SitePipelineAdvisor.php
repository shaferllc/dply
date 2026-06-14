<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Livewire\Sites\Concerns\ManagesSiteDeploySteps;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Sites\OctaneRuntimeVerifier;

/**
 * Inspects a site's deploy pipeline against its detected stack and flags the
 * steps it's MISSING — the proactive "suggest things before it breaks" half of
 * the deploy story. The classic trap: a pipeline that runs `npm ci` but never
 * `npm run build`, so the deploy "succeeds" while the live site 500s on a
 * missing Vite manifest.
 *
 * Each suggestion is a ready-to-add {@see SiteDeployStep}: a step_type + phase
 * (+ command for custom steps), so the UI can wire a one-click "Add to
 * pipeline" via {@see ManagesSiteDeploySteps::addDeployPipelineStepFromPalette()}.
 *
 * Pure + read-only — give it a Site, get suggestions back.
 */
final class SitePipelineAdvisor
{
    /** Meta key holding the list of suggestion keys the operator has dismissed. */
    public const DISMISSED_META_KEY = 'pipeline_suggestions_dismissed';

    /**
     * @param  bool  $includeDismissed  Return suggestions the operator has dismissed too (for autofix lookup by key).
     * @return list<array{key: string, label: string, reason: string, step_type: string, phase: string, command: ?string, priority: string}>
     */
    public static function suggestions(Site $site, bool $includeDismissed = false): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isVmHost()) {
            return [];
        }
        // Container/serverless/edge runtimes don't use this step pipeline.
        if ($site->usesFunctionsRuntime() || $site->usesEdgeRuntime()) {
            return [];
        }

        // Cached relation property so this shares the steps query with the deploy
        // timeline when both run against the same $site instance in one request.
        $site->loadMissing('deploySteps');
        $steps = $site->deploySteps;
        $types = $steps->pluck('step_type')->map(static fn ($t): string => (string) $t)->all();
        $customCommands = $steps
            ->where('step_type', SiteDeployStep::TYPE_CUSTOM)
            ->map(static fn ($s): string => strtolower((string) $s->custom_command));

        $has = static fn (string $type): bool => in_array($type, $types, true);
        $hasAny = static fn (array $list): bool => array_intersect($list, $types) !== [];
        $customMatches = static fn (string $needle): bool => $customCommands->contains(static fn (string $c): bool => str_contains($c, $needle));

        $detection = $site->resolvedRuntimeAppDetection() ?? [];
        $detectedFiles = array_map('strtolower', array_filter((array) ($detection['detected_files'] ?? []), 'is_string'));
        $hasFile = static fn (string $needle): bool => collect($detectedFiles)->contains(static fn (string $f): bool => str_contains($f, $needle));
        $isLaravel = $site->isLaravelFrameworkDetected();

        $out = [];

        // ---- Front-end asset build -------------------------------------
        $jsInstallTypes = [
            SiteDeployStep::TYPE_NPM_CI, SiteDeployStep::TYPE_NPM_INSTALL,
            SiteDeployStep::TYPE_YARN_INSTALL, SiteDeployStep::TYPE_PNPM_INSTALL,
            SiteDeployStep::TYPE_BUN_INSTALL,
        ];
        $jsInstall = $hasAny($jsInstallTypes);
        $jsBuild = $has(SiteDeployStep::TYPE_NPM_RUN) || $customMatches('run build') || $customMatches('vite build') || $customMatches('npm run prod');
        $needsBuild = $jsInstall || $hasFile('vite.config') || $hasFile('package.json') || ($isLaravel && $hasFile('package'));

        if ($needsBuild && ! $jsBuild) {
            if (! $jsInstall) {
                $out[] = self::make('npm_ci_for_build', __('Install JS dependencies'),
                    __('A front-end build needs its packages installed first (npm ci).'),
                    SiteDeployStep::TYPE_NPM_CI, null, 'high');
            }
            $cmd = match (true) {
                $has(SiteDeployStep::TYPE_YARN_INSTALL) => 'yarn build',
                $has(SiteDeployStep::TYPE_PNPM_INSTALL) => 'pnpm build',
                $has(SiteDeployStep::TYPE_BUN_INSTALL) => 'bun run build',
                default => 'npm run build',
            };
            $out[] = self::make('build_assets', __('Build front-end assets'),
                __('Your pipeline installs JS dependencies but never builds them — the live site will 500 with "Vite manifest not found".'),
                SiteDeployStep::TYPE_CUSTOM, $cmd, 'high');
        }

        // ---- Laravel ----------------------------------------------------
        if ($isLaravel) {
            if (! $hasAny([SiteDeployStep::TYPE_ARTISAN_MIGRATE, SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND])) {
                $out[] = self::make('migrate', __('Run database migrations'),
                    __('No migrate step — schema changes won\'t apply, and the app can 500 on missing tables.'),
                    SiteDeployStep::TYPE_ARTISAN_MIGRATE, null, 'high');
            }
            if (! $hasAny([SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE])) {
                $out[] = self::make('optimize', __('Cache config & routes (optimize)'),
                    __('No optimize/config:cache step — slower boots and the app reads .env on every request in production.'),
                    SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, null, 'medium');
            }
            if (! $has(SiteDeployStep::TYPE_ARTISAN_STORAGE_LINK)) {
                $out[] = self::make('storage_link', __('Link public storage'),
                    __('No storage:link step — files in storage/app/public won\'t be web-accessible.'),
                    SiteDeployStep::TYPE_ARTISAN_STORAGE_LINK, null, 'low');
            }
            // Horizon restart is handled post-cutover by dply's managed restart
            // (guarded on the package + command), so we don't suggest adding an
            // explicit horizon:terminate step anymore.
            //
            // Octane: composer mentioning laravel/octane isn't enough — we only
            // nudge `octane:reload` once a queued probe has confirmed Octane is
            // actually installed AND serving this site (octane in composer but
            // served by FPM would make the step a no-op / failure). The probe
            // writes that verdict to meta; this render path only reads it.
            if (! empty($detection['laravel_octane'])
                && OctaneRuntimeVerifier::verifiedWorking($site)
                && ! $customMatches('octane:reload')) {
                $out[] = self::make('octane', __('Reload Octane workers'),
                    __('Octane is installed and serving this site, but workers aren\'t reloaded on deploy — they serve stale code.'),
                    SiteDeployStep::TYPE_CUSTOM, 'php artisan octane:reload', 'medium');
            }
        }

        if (! $includeDismissed) {
            $dismissed = self::dismissedKeys($site);
            if ($dismissed !== []) {
                $out = array_values(array_filter(
                    $out,
                    static fn (array $s): bool => ! in_array($s['key'], $dismissed, true),
                ));
            }
        }

        return $out;
    }

    /**
     * How many detected suggestions are currently hidden by a dismissal — so the
     * UI can offer a "Restore N dismissed" affordance.
     */
    public static function dismissedCount(Site $site): int
    {
        $dismissed = self::dismissedKeys($site);
        if ($dismissed === []) {
            return 0;
        }

        $activeKeys = array_column(self::suggestions($site, true), 'key');

        return count(array_intersect($dismissed, $activeKeys));
    }

    /**
     * @return list<string>
     */
    private static function dismissedKeys(Site $site): array
    {
        return array_values(array_filter(
            (array) data_get($site->meta, self::DISMISSED_META_KEY, []),
            'is_string',
        ));
    }

    /**
     * @return array{key: string, label: string, reason: string, step_type: string, phase: string, command: ?string, priority: string}
     */
    private static function make(string $key, string $label, string $reason, string $stepType, ?string $command, string $priority): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
            'step_type' => $stepType,
            'phase' => SiteDeployStep::defaultPhaseFor($stepType),
            'command' => $command,
            'priority' => $priority,
        ];
    }
}
