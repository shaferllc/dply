<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves effective RUM alert thresholds by merging dply.yaml with
 * dashboard `edgeMeta.alerts`. Dashboard wins per metric when both
 * supply the same key — same operator-override model as
 * {@see EdgeEffectiveErrorPages}.
 */
final class EdgeEffectiveAlerts
{
    public const KEYS = ['lcp_p75_ms', 'error_rate', 'five_xx_count'];

    /**
     * @return array{
     *     lcp_p75_ms: array{enabled: bool, threshold: int},
     *     error_rate: array{enabled: bool, threshold: float},
     *     five_xx_count: array{enabled: bool, threshold: int},
     *     sources: array{repo: bool, dashboard: bool}
     * }
     */
    public static function for(Site $site, ?EdgeDeployment $deployment = null): array
    {
        $deployment ??= EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repo = self::extractRepo($deployment);
        $dashboard = self::extractDashboard($site);

        $defaults = [
            'lcp_p75_ms' => ['enabled' => false, 'threshold' => 2500],
            'error_rate' => ['enabled' => false, 'threshold' => 5.0],
            'five_xx_count' => ['enabled' => false, 'threshold' => 50],
        ];

        $merged = [];
        foreach (self::KEYS as $key) {
            $base = $defaults[$key];
            $fromRepo = is_array($repo[$key] ?? null) ? $repo[$key] : null;
            $fromDash = is_array($dashboard[$key] ?? null) ? $dashboard[$key] : null;
            $chosen = $fromDash ?? $fromRepo ?? $base;

            $enabled = (bool) $chosen['enabled'];
            $threshold = $chosen['threshold'];
            $merged[$key] = [
                'enabled' => $enabled,
                'threshold' => $key === 'error_rate' ? (float) $threshold : (int) $threshold,
            ];
        }

        return [
            ...$merged,
            'sources' => [
                'repo' => $repo !== [],
                'dashboard' => $dashboard !== [],
            ],
        ];
    }

    /**
     * True when any metric is enabled (so the hourly checker should run).
     *
     * @param  array<string, mixed>  $effective
     */
    public static function anyEnabled(array $effective): bool
    {
        foreach (self::KEYS as $key) {
            $metric = is_array($effective[$key] ?? null) ? $effective[$key] : null;
            if ($metric !== null && ($metric['enabled'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{enabled: bool, threshold: float|int}> */
    private static function extractRepo(?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $alerts = is_array($repoConfig['alerts'] ?? null) ? $repoConfig['alerts'] : [];

        return self::sanitize($alerts);
    }

    /** @return array<string, array{enabled: bool, threshold: float|int}> */
    private static function extractDashboard(Site $site): array
    {
        $alerts = is_array($site->edgeMeta()['alerts'] ?? null) ? $site->edgeMeta()['alerts'] : [];

        return self::sanitize($alerts);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, array{enabled: bool, threshold: float|int}>
     */
    private static function sanitize(array $value): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $entry = is_array($value[$key] ?? null) ? $value[$key] : null;
            if ($entry === null || ! array_key_exists('threshold', $entry)) {
                continue;
            }
            if (! is_numeric($entry['threshold'])) {
                continue;
            }
            $out[$key] = [
                'enabled' => (bool) ($entry['enabled'] ?? false),
                'threshold' => $key === 'error_rate'
                    ? (float) $entry['threshold']
                    : (int) $entry['threshold'],
            ];
        }

        return $out;
    }
}
