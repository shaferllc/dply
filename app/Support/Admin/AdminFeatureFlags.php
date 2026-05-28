<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final class AdminFeatureFlags
{
    /**
     * @return array<string, string>
     */
    public static function productLineSlugs(): array
    {
        $lines = [];
        foreach (self::productLines() as $slug => $line) {
            $lines[$slug] = is_string($line['title'] ?? null) ? $line['title'] : $slug;
        }

        return $lines;
    }

    public static function productLineTitle(string $slug): ?string
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) && is_string($line['title'] ?? null) ? $line['title'] : null;
    }

    public static function productLineDescription(string $slug): ?string
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) && is_string($line['description'] ?? null) ? $line['description'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function productLine(string $slug): ?array
    {
        $line = self::productLines()[$slug] ?? null;

        return is_array($line) ? $line : null;
    }

    /**
     * @return array<string, string>
     */
    public static function emergencyFlagsForProductLine(string $slug): array
    {
        $line = self::productLine($slug);

        return is_array($line['emergency'] ?? null) ? $line['emergency'] : [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupsForProductLine(string $slug): array
    {
        $line = self::productLine($slug);

        return is_array($line['groups'] ?? null) ? $line['groups'] : [];
    }

    /**
     * @return array<string, string>
     */
    public static function flagsForProductLine(string $slug): array
    {
        $flags = [];
        foreach (self::groupsForProductLine($slug) as $groupFlags) {
            foreach ($groupFlags as $key => $label) {
                $flags[$key] = $label;
            }
        }

        return $flags;
    }

    public static function productLineRoute(string $slug): ?string
    {
        return match ($slug) {
            'vm-servers' => 'admin.flags.vm.servers',
            'vm-sites' => 'admin.flags.vm.sites',
            'cloud' => 'admin.flags.cloud',
            'edge' => 'admin.flags.edge',
            'serverless' => 'admin.flags.serverless',
            'platform' => 'admin.flags.platform',
            default => null,
        };
    }

    public static function legacyDefaultGroupRedirectTarget(string $group): ?string
    {
        $map = config('admin_feature_flags.legacy_default_group_redirects', []);

        return is_string($map[$group] ?? null) ? $map[$group] : null;
    }

    public static function legacyOrgTabRedirectTarget(string $tab): ?string
    {
        $map = config('admin_feature_flags.legacy_org_tab_redirects', []);

        return is_string($map[$tab] ?? null) ? $map[$tab] : null;
    }

    public static function resolveOrgTab(string $tab): string
    {
        $legacy = self::legacyOrgTabRedirectTarget($tab);
        if ($legacy !== null) {
            return $legacy;
        }

        return self::productLineTitle($tab) !== null ? $tab : 'vm-servers';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function productLines(): array
    {
        return config('admin_feature_flags.product_lines', []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function orgGroups(): array
    {
        $groups = [];
        foreach (self::productLines() as $line) {
            if (! is_array($line['groups'] ?? null)) {
                continue;
            }
            foreach ($line['groups'] as $title => $flags) {
                if (! isset($groups[$title])) {
                    $groups[$title] = [];
                }
                $groups[$title] = array_merge($groups[$title], $flags);
            }
        }

        return $groups;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function globalGroups(): array
    {
        return config('admin_feature_flags.global_groups', []);
    }

    /**
     * @return list<string>
     */
    public static function orgFlagKeys(): array
    {
        $keys = [];
        foreach (self::productLines() as $line) {
            if (! is_array($line['groups'] ?? null)) {
                continue;
            }
            foreach ($line['groups'] as $flags) {
                foreach (array_keys($flags) as $key) {
                    if (! self::isGlobalNamespace($key) && ! self::isPlatformOnlyOrgFlag($key)) {
                        $keys[] = $key;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Pennant keys editable as platform defaults on admin product-line pages.
     *
     * @return list<string>
     */
    public static function platformDefaultFlagKeys(): array
    {
        return array_values(array_unique([
            ...self::orgFlagKeys(),
            ...self::platformOnlyOrgFlags(),
        ]));
    }

    /**
     * @return list<string>
     */
    public static function globalFlagKeys(): array
    {
        $keys = [];
        foreach (self::globalGroups() as $flags) {
            foreach (array_keys($flags) as $key) {
                $keys[] = $key;
            }
        }

        foreach (self::productLines() as $line) {
            if (is_array($line['emergency'] ?? null)) {
                foreach (array_keys($line['emergency']) as $key) {
                    $keys[] = $key;
                }
            }
            if (is_array($line['groups'] ?? null)) {
                foreach ($line['groups'] as $flags) {
                    foreach (array_keys($flags) as $key) {
                        if (self::isGlobalNamespace($key)) {
                            $keys[] = $key;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public static function orgOverrideCount(Organization $org): int
    {
        return (int) DB::table('features')
            ->where('scope', Feature::serializeScope($org))
            ->whereIn('name', self::orgFlagKeys())
            ->count();
    }

    public static function orgOverrideCountForFlag(string $flag): int
    {
        if (! in_array($flag, self::orgFlagKeys(), true)) {
            return 0;
        }

        return (int) DB::table('features')
            ->where('name', $flag)
            ->where('scope', 'like', self::orgScopeLikePrefix().'%')
            ->count();
    }

    /**
     * Remove stored Pennant values for every org so resolution falls back to
     * the platform default (null scope).
     */
    public static function purgeOrgScopedOverrides(string $flag): int
    {
        if (! in_array($flag, self::orgFlagKeys(), true) && ! self::isPlatformOnlyOrgFlag($flag)) {
            return 0;
        }

        return self::purgeOrgScopedOverridesRaw($flag);
    }

    public static function purgeOrgScopedOverridesRaw(string $flag): int
    {
        return DB::table('features')
            ->where('name', $flag)
            ->where('scope', 'like', self::orgScopeLikePrefix().'%')
            ->delete();
    }

    /**
     * Global flags must not be stored on org scopes; strip stray rows.
     */
    public static function purgeInvalidOrgScopedGlobalOverrides(string $flag): int
    {
        if (! self::isGlobalNamespace($flag)) {
            return 0;
        }

        return DB::table('features')
            ->where('name', $flag)
            ->where('scope', 'like', self::orgScopeLikePrefix().'%')
            ->delete();
    }

    /**
     * @return array<string, int>
     */
    public static function bulkOrgOverrideCounts(): array
    {
        $prefix = self::orgScopeLikePrefix();

        $counts = DB::table('features')
            ->where('scope', 'like', $prefix.'%')
            ->whereIn('name', self::orgFlagKeys())
            ->selectRaw('scope, count(*) as c')
            ->groupBy('scope')
            ->pluck('c', 'scope');

        $result = [];
        $orgPrefix = Organization::class.'|';
        foreach ($counts as $scope => $count) {
            $id = str_replace($orgPrefix, '', (string) $scope);
            $result[$id] = (int) $count;
        }

        return $result;
    }

    public static function orgFlagLabel(string $key): ?string
    {
        foreach (self::orgGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        foreach (self::productLines() as $line) {
            if (is_array($line['emergency'] ?? null) && isset($line['emergency'][$key])) {
                return $line['emergency'][$key];
            }
        }

        return null;
    }

    public static function globalFlagLabel(string $key): ?string
    {
        foreach (self::globalGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        foreach (self::productLines() as $line) {
            if (is_array($line['emergency'] ?? null) && isset($line['emergency'][$key])) {
                return $line['emergency'][$key];
            }
            if (is_array($line['groups'] ?? null)) {
                foreach ($line['groups'] as $flags) {
                    if (isset($flags[$key])) {
                        return $flags[$key];
                    }
                }
            }
        }

        return null;
    }

    public static function configDefault(string $key): bool
    {
        [$namespace, $leaf] = explode('.', $key, 2);

        return (bool) (config("features.{$namespace}.{$leaf}") ?? false);
    }

    public static function isGlobalNamespace(string $key): bool
    {
        return str_starts_with($key, 'global.');
    }

    /**
     * @return list<string>
     */
    public static function platformOnlyOrgFlags(): array
    {
        return config('admin_feature_flags.platform_only_org_flags', []);
    }

    public static function isPlatformOnlyOrgFlag(string $key): bool
    {
        return in_array($key, self::platformOnlyOrgFlags(), true);
    }

    public static function platformOrgFlagActive(string $key): bool
    {
        return Feature::for(null)->active($key);
    }

    public static function platformDefault(string $key): bool
    {
        return Feature::for(null)->active($key);
    }

    private static function orgScopeLikePrefix(): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Organization::class).'|';
    }

    /**
     * @deprecated Use {@see productLineSlugs()} — kept for redirect helpers.
     *
     * @return array<string, string>
     */
    public static function defaultGroupSlugs(): array
    {
        return config('admin_feature_flags.legacy_default_group_redirects', []);
    }
}
