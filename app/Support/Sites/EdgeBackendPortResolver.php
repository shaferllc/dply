<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Stable high port for Caddy backends behind an L7 edge proxy (Traefik / HAProxy / Envoy).
 */
final class EdgeBackendPortResolver
{
    public static function for(Site $site): int
    {
        $meta = ($site->meta );

        foreach (['edge_backend_port', 'traefik_backend_port'] as $key) {
            $existing = $meta[$key] ?? null;
            if (is_numeric($existing) && (int) $existing >= 20000) {
                return (int) $existing;
            }
        }

        return 20000 + (abs(crc32((string) $site->getKey())) % 20000);
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaWithPinnedPort(Site $site, int $port): array
    {
        $meta = ($site->meta );
        $meta['edge_backend_port'] = $port;
        $meta['traefik_backend_port'] = $port;

        return $meta;
    }
}
