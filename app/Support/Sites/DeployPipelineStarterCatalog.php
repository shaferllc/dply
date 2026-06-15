<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use InvalidArgumentException;

/**
 * Built-in full pipeline starters (Rollout + steps + optional safety hooks).
 */
final class DeployPipelineStarterCatalog
{
    public function __construct(
        private readonly RuntimeAwareDeployStepDefaults $runtimeDefaults,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     icon: string,
     *     strategy: string,
     *     new_pipeline_name: string,
     * }>
     */
    public function startersForSite(Site $site): array
    {
        $starters = [];
        $config = (array) config('site_deploy_pipeline_starters.starters', []);

        $items = collect($config)
            ->map(fn (array $meta, string $key): array => ['key' => $key, 'meta' => $meta])
            ->sortBy(fn (array $row) => (int) ($row['meta']['order'] ?? 999))
            ->values();

        foreach ($items as $row) {
            $key = (string) $row['key'];
            $meta = $row['meta'];
            if (! $this->visibleForSite($site, $meta)) {
                continue;
            }
            $starters[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'description' => (string) ($meta['description'] ?? ''),
                'icon' => (string) ($meta['icon'] ?? 'heroicon-o-rectangle-stack'),
                'strategy' => (string) ($meta['strategy'] ?? 'simple'),
                'new_pipeline_name' => (string) ($meta['new_pipeline_name'] ?? $meta['label'] ?? $key),
            ];
        }

        return $starters;
    }

    /**
     * @return array<string, mixed>
     */
    public function starterMeta(string $key): array
    {
        $meta = config("site_deploy_pipeline_starters.starters.{$key}");
        if (! is_array($meta)) {
            throw new InvalidArgumentException(__('Unknown pipeline starter.'));
        }

        return $meta;
    }

    public function visibleForSite(Site $site, ?array $meta = null, ?string $key = null): bool
    {
        if ($meta === null && $key !== null) {
            try {
                $meta = $this->starterMeta($key);
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        if ($meta === null) {
            return false;
        }

        $requires = $meta['requires'] ?? null;
        if ($requires === null || $requires === '') {
            return true;
        }

        return match ((string) $requires) {
            'laravel' => $site->isLaravelFrameworkDetected() || $this->phpSiteUsesLaravelStarterDefaults($site),
            default => DeployPipelinePalette::entryVisible($site, ['requires' => (string) $requires]),
        };
    }

    /**
     * @return list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>
     */
    public function resolveSteps(Site $site, string $key): array
    {
        $meta = $this->starterMeta($key);
        $strategy = (string) ($meta['strategy'] ?? 'simple');

        if (isset($meta['steps']) && is_array($meta['steps'])) {
            return $this->normalizeSteps($meta['steps']);
        }

        if (($meta['steps_from'] ?? '') === 'runtime') {
            $steps = $this->runtimeDefaults->defaultsFor(
                $site->runtimeKey(),
                $this->runtimeFrameworkForStarterSteps($site),
            );

            if ($strategy === 'simple') {
                return $this->moveReleaseStepsToBuild($steps);
            }

            return $this->normalizeSteps($steps);
        }

        return [];
    }

    /**
     * Framework key for runtime-based starter steps (detection meta, then PHP → Laravel).
     */
    private function runtimeFrameworkForStarterSteps(Site $site): ?string
    {
        $detected = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? ''));
        if ($detected !== '' && $detected !== 'unknown') {
            return $detected;
        }

        return match ($site->runtimeKey()) {
            'php' => 'laravel',
            'ruby' => 'rails',
            default => null,
        };
    }

    private function phpSiteUsesLaravelStarterDefaults(Site $site): bool
    {
        return $site->runtimeKey() === 'php';
    }

    public function includesSafetyBundle(string $key): bool
    {
        return (bool) ($this->starterMeta($key)['include_safety_bundle'] ?? false);
    }

    public function strategyFor(string $key): string
    {
        $strategy = (string) ($this->starterMeta($key)['strategy'] ?? 'simple');

        return in_array($strategy, ['simple', 'atomic'], true) ? $strategy : 'simple';
    }

    public function defaultNewPipelineName(string $key): string
    {
        $meta = $this->starterMeta($key);

        return (string) ($meta['new_pipeline_name'] ?? $meta['label'] ?? $key);
    }

    /**
     * @return array{
     *     deploy_strategy: string,
     *     releases_to_keep: ?int,
     *     deploy_health_enabled: bool,
     *     deploy_health_auto_rollback: bool,
     *     deploy_health_path: string,
     * }
     */
    public function rolloutChangesFor(Site $site, string $key): array
    {
        $strategy = $this->strategyFor($key);

        if ($strategy === 'atomic') {
            return [
                'deploy_strategy' => 'atomic',
                'releases_to_keep' => 5,
                'deploy_health_enabled' => true,
                'deploy_health_auto_rollback' => true,
                // Probe the homepage, not /up: a bare health route 200s while the
                // real, Vite-rendering pages 500 (the checker always probes '/'
                // anyway). Keep a real route so the app's layout/assets are exercised.
                'deploy_health_path' => '/',
            ];
        }

        // Simple/flat deploys are HTTP-smoke-tested too — a flat overwrite can
        // 500 just as easily; it can't auto-rollback (no previous release symlink),
        // so the gate detects + fails the deploy rather than silently going green.
        return [
            'deploy_strategy' => 'simple',
            'releases_to_keep' => null,
            'deploy_health_enabled' => true,
            'deploy_health_auto_rollback' => false,
            'deploy_health_path' => '/',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>
     */
    private function normalizeSteps(array $steps): array
    {
        $normalized = [];
        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['step_type'])) {
                continue;
            }
            $row = [
                'step_type' => (string) $step['step_type'],
                'phase' => (string) ($step['phase'] ?? SiteDeployStep::PHASE_BUILD),
                'timeout_seconds' => (int) ($step['timeout_seconds'] ?? 900),
            ];
            if (isset($step['custom_command']) && trim((string) $step['custom_command']) !== '') {
                $row['custom_command'] = trim((string) $step['custom_command']);
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{step_type: string, phase: string, custom_command?: string, timeout_seconds: int}>
     */
    private function moveReleaseStepsToBuild(array $steps): array
    {
        $normalized = $this->normalizeSteps($steps);

        return array_map(function (array $step): array {
            if ($step['phase'] === SiteDeployStep::PHASE_RELEASE) {
                $step['phase'] = SiteDeployStep::PHASE_BUILD;
            }

            return $step;
        }, $normalized);
    }
}
