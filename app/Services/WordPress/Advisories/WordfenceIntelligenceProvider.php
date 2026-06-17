<?php

declare(strict_types=1);

namespace App\Services\WordPress\Advisories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wordfence Intelligence advisory feed (Q20 v1 default).
 *
 * Free-tier API at wfi-api.wordfence.com/v1/vulnerabilities — no key
 * required, ~100 req/IP/day. We cache every distinct (slug, version)
 * lookup for 24h so the WP Plugins tab + dashboard refreshes don't
 * burn the budget on read traffic. CVE data isn't minute-to-minute
 * fresh anyway.
 *
 * If the API is unreachable, we return an empty list rather than
 * propagate the error — a missing advisory is preferable to a broken
 * Plugins tab. The error gets logged for ops visibility.
 */
class WordfenceIntelligenceProvider implements AdvisoryProvider
{
    private const BASE_URL = 'https://www.wordfence.com/api/intelligence/v2/vulnerabilities';

    private const CACHE_TTL_SECONDS = 86_400;

    public function name(): string
    {
        return 'Wordfence Intelligence';
    }

    /** @return array<string, mixed> */
    /**
     * @return list<App\Services\WordPress\Advisories\Advisory>
     */
    public function forPlugin(string $slug, string $installedVersion): array
    {
        return $this->lookup('plugin', $slug, $installedVersion);
    }

    /** @return list<App\Services\WordPress\Advisories\Advisory>
    /** @return list<App\Services\WordPress\Advisories\Advisory>
    /**
     * @return list<App\Services\WordPress\Advisories\Advisory>
     */
    public function forTheme(string $slug, string $installedVersion): array
    {
        return $this->lookup('theme', $slug, $installedVersion);
    }

    /** @return list<App\Services\WordPress\Advisories\Advisory>
    /** @return list<App\Services\WordPress\Advisories\Advisory>
    /**
     * @return list<App\Services\WordPress\Advisories\Advisory>
     */
    public function forCore(string $installedVersion): array
    {
        return $this->lookup('core', 'wordpress', $installedVersion);
    }

    /**
     * @return list<App\Services\WordPress\Advisories\Advisory>
     */
    private function lookup(string $kind, string $slug, string $version): array
    {
        $cacheKey = sprintf('wp-advisories:wfi:%s:%s:%s', $kind, $slug, $version);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($kind, $slug, $version): array {
            try {
                $response = Http::acceptJson()
                    ->timeout(8)
                    ->get(self::BASE_URL.'/scanner/scan', [
                        'type' => $kind,
                        'slug' => $slug,
                        'installed_version' => $version,
                    ]);
            } catch (Throwable $e) {
                Log::info('WordfenceIntelligenceProvider: lookup failed (returning empty)', [
                    'kind' => $kind,
                    'slug' => $slug,
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }

            if ($response->failed()) {
                return [];
            }

            $records = $response->json('vulnerabilities') ?? $response->json() ?? [];
            if (! is_array($records)) {
                return [];
            }

            $advisories = [];
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $advisories[] = Advisory::fromWordfence($record);
            }

            return $advisories;
        });
    }
}
