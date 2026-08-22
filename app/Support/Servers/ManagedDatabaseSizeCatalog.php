<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\CloudDatabase;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Providers\Services\DigitalOceanService;

/**
 * Live managed-database size slugs for the server's provider account.
 */
final class ManagedDatabaseSizeCatalog
{
    /**
     * Official single-node monthly USD for DigitalOcean Valkey / managed DB
     * basic plans (https://www.digitalocean.com/pricing/managed-databases).
     *
     * @var array<string, int>
     */
    private const SLUG_MONTHLY = [
        'db-s-1vcpu-1gb' => 15,
        'db-s-1vcpu-2gb' => 30,
        'db-s-2vcpu-4gb' => 60,
        'db-s-4vcpu-8gb' => 120,
        'db-s-6vcpu-16gb' => 240,
        'db-s-8vcpu-32gb' => 480,
        'db-s-16vcpu-64gb' => 960,
    ];

    /**
     * Same prices keyed by vCPU×RAM so Intel/AMD twin slugs still show a cost
     * when they win the collapse, and so we do not leave 6/16+ unpriced.
     *
     * @var array<string, int>
     */
    private const BASIC_SPEC_MONTHLY = [
        '1x1' => 15,
        '1x2' => 30,
        '2x4' => 60,
        '4x8' => 120,
        '6x16' => 240,
        '8x32' => 480,
        '16x64' => 960,
    ];

    /**
     * @return list<string>
     */
    public static function slugs(Server $server, string $engine, ?ProviderCredential $credential = null): array
    {
        $slugs = ManagedDatabaseCatalogAuth::firstSuccessful(
            $server,
            $credential,
            static fn (DigitalOceanService $service): array => $service->getDatabaseEngineSizes($engine),
        );

        return is_array($slugs) ? $slugs : [];
    }

    /**
     * Pick a create-valid size from the live catalog. Portable tiers
     * (`small`) map only when that slug is still offered.
     */
    public static function resolve(Server $server, string $engine, ?string $requested, ?ProviderCredential $credential = null): ?string
    {
        $available = self::slugs($server, $engine, $credential);
        if ($available === []) {
            return null;
        }

        $raw = strtolower(trim((string) $requested));
        if ($raw !== '' && array_key_exists($raw, CloudDatabase::SIZE_TIERS)) {
            $mapped = CloudDatabase::SIZE_TIERS[$raw];
            if (in_array($mapped, $available, true)) {
                return $mapped;
            }
        }

        if ($raw !== '' && in_array($raw, $available, true)) {
            return $raw;
        }

        return $available[0];
    }

    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function options(Server $server, string $engine, ?ProviderCredential $credential = null): array
    {
        return self::optionsFromSlugs(self::slugs($server, $engine, $credential));
    }

    /**
     * One row per (family × vCPU × RAM). DigitalOcean lists the same shape
     * as basic / Intel / AMD slugs; operators only need the standard plan.
     *
     * @param  list<string>  $slugs
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function optionsFromSlugs(array $slugs): array
    {
        $picked = [];
        foreach ($slugs as $slug) {
            $slug = strtolower(trim($slug));
            if ($slug === '') {
                continue;
            }

            $key = self::collapseKey($slug);
            if ($key !== null && isset($picked[$key]) && ! self::preferSlug($slug, $picked[$key])) {
                continue;
            }

            $picked[$key ?? $slug] = $slug;
        }

        $options = array_map(
            static fn (string $slug): array => [
                'value' => $slug,
                'label' => self::label($slug),
                'group' => self::group($slug),
            ],
            array_values($picked),
        );

        usort($options, static function (array $left, array $right): int {
            $group = self::groupRank((string) $left['group']) <=> self::groupRank((string) $right['group']);
            if ($group !== 0) {
                return $group;
            }

            return self::rank((string) $left['value']) <=> self::rank((string) $right['value']);
        });

        return $options;
    }

    /**
     * Comparable rank for upsize vs downsize copy. Larger vCPU/RAM wins.
     */
    public static function rank(string $slug): int
    {
        $spec = self::spec($slug);
        if ($spec === null) {
            return 0;
        }

        return ($spec['cpu'] * 10_000) + $spec['ram'];
    }

    public static function label(string $slug): string
    {
        $spec = self::spec($slug);
        $label = $spec !== null
            ? __(':cpu vCPU / :ram GB', ['cpu' => $spec['cpu'], 'ram' => $spec['ram']])
            : $slug;

        $price = self::monthlyUsd($slug);
        if ($price !== null) {
            $label .= ' · ~$'.$price.'/mo';
        }

        return $label;
    }

    public static function monthlyUsd(string $slug): ?int
    {
        $slug = strtolower(trim($slug));
        if (isset(self::SLUG_MONTHLY[$slug])) {
            return self::SLUG_MONTHLY[$slug];
        }

        $spec = self::spec($slug);
        if ($spec === null || self::family($slug) !== 'basic') {
            return null;
        }

        return self::BASIC_SPEC_MONTHLY[$spec['cpu'].'x'.$spec['ram']] ?? null;
    }

    public static function group(string $slug): string
    {
        return match (self::family($slug)) {
            'memory' => __('Memory-optimized'),
            'storage' => __('Storage-optimized'),
            default => __('Basic'),
        };
    }

    /**
     * @return array{cpu: int, ram: int}|null
     */
    private static function spec(string $slug): ?array
    {
        if (preg_match('/(\d+)vcpu-(\d+)gb/i', $slug, $matches) !== 1) {
            return null;
        }

        return [
            'cpu' => (int) $matches[1],
            'ram' => (int) $matches[2],
        ];
    }

    private static function family(string $slug): string
    {
        $slug = strtolower($slug);
        if (str_starts_with($slug, 'm-') || str_starts_with($slug, 'gd-')) {
            return 'memory';
        }
        if (str_starts_with($slug, 'so-')) {
            return 'storage';
        }

        return 'basic';
    }

    private static function collapseKey(string $slug): ?string
    {
        $spec = self::spec($slug);
        if ($spec === null) {
            return null;
        }

        return self::family($slug).':'.$spec['cpu'].'x'.$spec['ram'];
    }

    private static function preferSlug(string $candidate, string $current): bool
    {
        return self::slugScore($candidate) > self::slugScore($current);
    }

    private static function slugScore(string $slug): int
    {
        $score = 0;
        if (str_starts_with($slug, 'db-s-')) {
            $score += 100;
        }
        if (isset(self::SLUG_MONTHLY[$slug])) {
            $score += 10;
        }

        return $score;
    }

    private static function groupRank(string $group): int
    {
        return match ($group) {
            __('Basic') => 0,
            __('Memory-optimized') => 1,
            __('Storage-optimized') => 2,
            default => 3,
        };
    }
}
