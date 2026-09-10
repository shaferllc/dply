<?php

declare(strict_types=1);

namespace App\Services\WordPress;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WordPress core releases, for the Core tab's version picker and security badge.
 *
 * Same rules as {@see PluginDirectory}: called from the control plane, cached
 * globally, degrades to empty, and failures are never cached. Shapes verified
 * against the live API (2026-09-10): `version-check/1.7` offers the latest
 * point release of every branch with its PHP minimum; `stable-check/1.0` maps
 * every version ever released to latest / outdated / insecure.
 */
final class CoreReleases
{
    /**
     * ponytail: picker floor. Older branches predate PHP 8 support and are a
     * footgun on a dply box; lower it if someone genuinely needs 5.x.
     */
    private const OLDEST_BRANCH = '6.0';

    /**
     * Latest point release of each branch, newest first — the security-patched
     * release per branch, which is what anyone switching versions should land on.
     *
     * @return list<array{version: string, php: string, mysql: string}>
     */
    public function branches(): array
    {
        try {
            return Cache::remember('wporg:core:branches', 21600, function (): array {
                $releases = [];
                foreach ((array) ($this->fetch('https://api.wordpress.org/core/version-check/1.7/')['offers'] ?? []) as $offer) {
                    $version = is_array($offer) ? (string) ($offer['current'] ?? '') : '';
                    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1 || isset($releases[$version])
                        || version_compare($version, self::OLDEST_BRANCH, '<')) {
                        continue;
                    }
                    $releases[$version] = [
                        'version' => $version,
                        'php' => (string) ($offer['php_version'] ?? ''),
                        'mysql' => (string) ($offer['mysql_version'] ?? ''),
                    ];
                }

                if ($releases === []) {
                    // Thrown, not returned: an empty list must not be cached for six hours.
                    throw new \RuntimeException('wordpress.org returned no core offers');
                }

                $list = array_values($releases);
                usort($list, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

                return $list;
            });
        } catch (\Throwable $e) {
            Log::info('CoreReleases branches failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return 'latest'|'outdated'|'insecure'|null */
    public function status(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        try {
            $map = Cache::remember('wporg:core:stable-check', 21600, fn (): array => $this->fetch('https://api.wordpress.org/core/stable-check/1.0/'));
        } catch (\Throwable $e) {
            Log::info('CoreReleases status failed', ['error' => $e->getMessage()]);

            return null;
        }

        $status = $map[$version] ?? null;

        return in_array($status, ['latest', 'outdated', 'insecure'], true) ? $status : null;
    }

    /**
     * What stands between this site and $release.
     *
     * @param  array{version: string, php: string}  $release
     * @return array{blockers: list<string>, warnings: list<string>}
     */
    public static function compatibility(array $release, ?string $installed, ?string $phpVersion): array
    {
        $blockers = [];
        $warnings = [];

        if ($release['php'] !== '' && $phpVersion && version_compare($phpVersion, $release['php'], '<')) {
            $blockers[] = __('WordPress :v requires PHP :need — this site runs :have.', ['v' => $release['version'], 'need' => $release['php'], 'have' => $phpVersion]);
        }

        if ($installed && version_compare($release['version'], $installed, '<')) {
            $warnings[] = __('Older than the installed :installed. WordPress never downgrades its database, so the schema stays at the newer version — going back down is not a supported WordPress path.', ['installed' => $installed]);
        }

        // ponytail: heuristic — 6.4 is the first branch shipped with PHP 8.2 in its test matrix.
        if ($phpVersion && version_compare($phpVersion, '8.2', '>=') && version_compare($release['version'], '6.4', '<')) {
            $warnings[] = __('WordPress :v predates PHP 8.2 support; on PHP :php expect deprecation notices or breakage.', ['v' => $release['version'], 'php' => $phpVersion]);
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * Throws on any failure so Cache::remember never stores it.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $url): array
    {
        $response = Http::acceptJson()->timeout(8)->get($url);
        if (! $response->ok() || ! is_array($response->json())) {
            throw new \RuntimeException('wordpress.org returned HTTP '.$response->status());
        }

        return $response->json();
    }
}
