<?php

declare(strict_types=1);

namespace App\Support\Edge;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Finds org Cloud container sites suitable as hybrid Edge SSR origins.
 */
final class HybridEdgeOriginMatcher
{
    /**
     * Prefer a Cloud app on the same normalized Git repo with a live URL.
     */
    public static function findForRepo(Organization $organization, string $repo): ?Site
    {
        $repo = self::normalizeRepo($repo);
        if ($repo === '') {
            return null;
        }

        foreach (self::orgCloudSites($organization) as $site) {
            $container = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
            $source = is_array($container['source'] ?? null) ? $container['source'] : [];
            $sourceRepo = is_string($source['repo'] ?? null) ? self::normalizeRepo((string) $source['repo']) : '';
            if ($sourceRepo !== '' && $sourceRepo === $repo && $site->containerLiveUrl() !== null) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Fallback match by Edge app slug/name when repo matching fails.
     */
    public static function findForEdgeName(Organization $organization, string $edgeName): ?Site
    {
        $slug = Str::slug($edgeName) ?: 'app';

        foreach (self::orgCloudSites($organization) as $site) {
            if (
                ($site->slug === $slug || Str::slug((string) $site->name) === $slug)
                && $site->containerLiveUrl() !== null
            ) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Site>
     */
    private static function orgCloudSites(Organization $organization): \Illuminate\Support\Collection
    {
        return Site::query()
            ->where('organization_id', $organization->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->orderBy('name')
            ->get();
    }

    public static function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
