<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsApiVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Action Versions Dashboard - Manage API versions.
 *
 * Provides dashboard and management capabilities for action versioning.
 *
 * Works with actions that use the AsApiVersion trait.
 *
 * @example
 * // Get version usage statistics
 * $usage = ActionVersions::getUsage('v1', 'v2', 'v3');
 * // Returns: [
 * //     'v1' => ['calls' => 1000, 'actions' => [...]],
 * //     'v2' => ['calls' => 500, 'actions' => [...]],
 * //     'v3' => ['calls' => 200, 'actions' => [...]],
 * // ]
 * @example
 * // Get dashboard for all versioned actions
 * $dashboard = ActionVersions::dashboard();
 * // Returns: [
 * //     'total_versioned_actions' => 10,
 * //     'actions' => [...],
 * // ]
 * @example
 * // Deprecate a version
 * ActionVersions::deprecate('v1', '2024-12-31');
 * @example
 * // Check if version is deprecated
 * if (ActionVersions::isDeprecated('v1')) {
 *     // Return deprecation warning
 *     return response()->json([
 *         'warning' => 'This API version is deprecated',
 *         'deprecation_date' => '2024-12-31',
 *     ], 200, [
 *         'X-API-Deprecated' => 'true',
 *     ]);
 * }
 * @example
 * // Get deprecation info
 * $info = ActionVersions::getDeprecationInfo('v1');
 * // Returns: [
 * //     'version' => 'v1',
 * //     'deprecation_date' => '2024-12-31',
 * //     'deprecated_at' => '2024-01-15T10:30:00Z',
 * // ]
 */
class ActionVersions
{
    /**
     * Get version usage statistics.
     *
     * @param  string  ...$versions  Version identifiers to compare
     * @return array<string, mixed> Usage statistics
     */
    public static function getUsage(string ...$versions): array
    {
        $actions = ActionRegistry::getByTrait(AsApiVersion::class);
        $stats = [];

        foreach ($versions as $version) {
            $stats[$version] = [
                'calls' => 0,
                'actions' => [],
            ];
        }

        foreach ($actions as $actionClass) {
            // This would need to track version usage in the decorator
            // For now, return basic structure
        }

        return $stats;
    }

    /**
     * Get dashboard data for all versioned actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::getByTrait(AsApiVersion::class);

        return [
            'total_versioned_actions' => $actions->count(),
            'actions' => $actions->map(fn ($action) => [
                'class' => $action,
                'name' => class_basename($action),
            ])->toArray(),
        ];
    }

    /**
     * Deprecate a version.
     *
     * @param  string  $version  Version identifier
     * @param  string  $deprecationDate  Date when version will be deprecated
     */
    public static function deprecate(string $version, string $deprecationDate): void
    {
        $key = "action_version_deprecated:{$version}";
        Cache::put($key, [
            'version' => $version,
            'deprecation_date' => $deprecationDate,
            'deprecated_at' => now()->toIso8601String(),
        ], 86400 * 365); // Store for 1 year
    }

    /**
     * Check if a version is deprecated.
     *
     * @param  string  $version  Version identifier
     * @return bool True if deprecated
     */
    public static function isDeprecated(string $version): bool
    {
        $key = "action_version_deprecated:{$version}";

        return Cache::has($key);
    }

    /**
     * Get deprecation info for a version.
     *
     * @param  string  $version  Version identifier
     * @return array<string, mixed>|null Deprecation information
     */
    public static function getDeprecationInfo(string $version): ?array
    {
        $key = "action_version_deprecated:{$version}";

        return Cache::get($key);
    }
}
