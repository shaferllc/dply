<?php

declare(strict_types=1);

namespace App\Services\WordPress;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only client for the WordPress.org plugin directory — and, through
 * {@see ThemeDirectory}, the theme directory. Both APIs share one shape
 * (`/{kind}s/info/1.2/`, `query_{kind}s`, `{kind}_information`); only the
 * fields and the row normalization differ.
 *
 * Called from the control plane, never from the customer's server: a site must
 * not need outbound access to wordpress.org for its dashboard to work, and the
 * answers are identical for every site, so they cache globally.
 *
 * Every public method degrades to an empty result. Search suggestions and
 * recommendations are conveniences — a wordpress.org outage must never break
 * the Plugins tab. Failures are also never cached: a blip would otherwise blank
 * recommendations for the whole TTL.
 */
class PluginDirectory
{
    /** Also keys the cache, so plugin and theme answers can never cross. */
    protected const KIND = 'plugin';

    protected const FIELDS = [
        'short_description', 'rating', 'num_ratings', 'active_installs',
        'requires', 'requires_php', 'tested', 'last_updated', 'icons', 'version', 'author',
    ];

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return $this->query(
            ['search' => $term, 'per_page' => $limit],
            'search:'.mb_strtolower($term).':'.$limit,
            3600,
        );
    }

    /**
     * @param  'popular'|'featured'|'new'|'updated'  $list
     * @return list<array<string, mixed>>
     */
    public function browse(string $list = 'popular', int $limit = 12): array
    {
        return $this->query(['browse' => $list, 'per_page' => $limit], 'browse:'.$list.':'.$limit, 21600);
    }

    /** @return array<string, mixed>|null */
    public function info(string $slug): ?array
    {
        $slug = trim($slug);
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug) !== 1) {
            return null;
        }

        try {
            return Cache::remember('wporg:'.static::KIND.':info:'.$slug, 3600, function () use ($slug): array {
                $row = $this->fetch([
                    'action' => static::KIND.'_information',
                    'request' => [
                        'slug' => $slug,
                        'fields' => array_fill_keys(static::FIELDS, true) + ['versions' => true, 'sections' => false],
                    ],
                ]);

                $plugin = $this->normalize($row);

                // Newest first; `trunk` is the unreleased development head.
                $versions = array_values(array_filter(
                    array_map('strval', array_keys((array) ($row['versions'] ?? []))),
                    static fn (string $v): bool => $v !== 'trunk',
                ));
                usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
                $plugin['versions'] = array_slice($versions, 0, 15);

                return $plugin;
            });
        } catch (\Throwable $e) {
            Log::info(static::class.' info failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * What stands between this plugin and a site running $wpVersion / $phpVersion.
     *
     * Blockers are hard minimums the plugin declares (wp.org refuses nothing, so
     * an install would succeed and then fatal on activation). "Tested up to" an
     * older WordPress is only a warning: most plugins keep working, but it is the
     * best available signal that the author has stopped paying attention.
     *
     * @param  array<string, mixed>  $plugin
     * @return array{blockers: list<string>, warnings: list<string>}
     */
    public static function compatibility(array $plugin, ?string $wpVersion, ?string $phpVersion): array
    {
        $blockers = [];
        $warnings = [];

        $requiresWp = trim((string) ($plugin['requires'] ?? ''));
        $requiresPhp = trim((string) ($plugin['requires_php'] ?? ''));
        $tested = trim((string) ($plugin['tested'] ?? ''));

        if ($requiresWp !== '' && $wpVersion && version_compare($wpVersion, $requiresWp, '<')) {
            $blockers[] = __('Requires WordPress :need — this site runs :have.', ['need' => $requiresWp, 'have' => $wpVersion]);
        }

        if ($requiresPhp !== '' && $phpVersion && version_compare($phpVersion, $requiresPhp, '<')) {
            $blockers[] = __('Requires PHP :need — this site runs :have.', ['need' => $requiresPhp, 'have' => $phpVersion]);
        }

        // Compare on major.minor: "tested 6.6" should not warn on a 6.6.2 site.
        if ($tested !== '' && $wpVersion) {
            $siteMinor = implode('.', array_slice(explode('.', $wpVersion), 0, 2));
            if (version_compare($tested, $siteMinor, '<')) {
                $warnings[] = __('Only tested up to WordPress :tested.', ['tested' => $tested]);
            }
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /** @return list<array<string, mixed>> */
    private function query(array $request, string $cacheKey, int $ttl): array
    {
        try {
            return Cache::remember('wporg:'.static::KIND.'s:'.$cacheKey, $ttl, function () use ($request): array {
                $body = $this->fetch([
                    'action' => 'query_'.static::KIND.'s',
                    'request' => $request + ['fields' => array_fill_keys(static::FIELDS, true)],
                ]);

                return array_values(array_map(
                    fn (array $p): array => $this->normalize($p),
                    array_filter((array) ($body[static::KIND.'s'] ?? []), 'is_array'),
                ));
            });
        } catch (\Throwable $e) {
            Log::info(static::class.' query failed', ['request' => $request, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Throws on any failure so Cache::remember never stores it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function fetch(array $params): array
    {
        $response = Http::acceptJson()->timeout(8)->get('https://api.wordpress.org/'.static::KIND.'s/info/1.2/', $params);

        if (! $response->ok()) {
            throw new \RuntimeException('wordpress.org returned HTTP '.$response->status());
        }

        $body = $response->json();
        if (! is_array($body) || isset($body['error'])) {
            throw new \RuntimeException('wordpress.org error: '.(string) ($body['error'] ?? 'unexpected response'));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    protected function normalize(array $p): array
    {
        $icons = (array) ($p['icons'] ?? []);
        $text = static fn (mixed $v): string => trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5));

        return [
            'slug' => (string) ($p['slug'] ?? ''),
            'name' => $text($p['name'] ?? ''),
            'author' => $text($p['author'] ?? ''),
            'description' => $text($p['short_description'] ?? ''),
            'version' => (string) ($p['version'] ?? ''),
            // wp.org reports rating as 0–100.
            'rating' => (int) ($p['rating'] ?? 0),
            'num_ratings' => (int) ($p['num_ratings'] ?? 0),
            'active_installs' => (int) ($p['active_installs'] ?? 0),
            // `requires` / `requires_php` come back as false when unset.
            'requires' => is_string($p['requires'] ?? null) ? $p['requires'] : '',
            'requires_php' => is_string($p['requires_php'] ?? null) ? $p['requires_php'] : '',
            'tested' => is_string($p['tested'] ?? null) ? $p['tested'] : '',
            'last_updated' => (string) ($p['last_updated'] ?? ''),
            'icon' => (string) ($icons['1x'] ?? $icons['default'] ?? $icons['svg'] ?? ''),
        ];
    }
}
