<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Allocates a free internal port for a non-PHP/static site on a given server.
 *
 * Per the multi-runtime strategy memo: "Per-site internal_port allocated
 * from 30000–39999 range." NGINX upstreams non-PHP runtimes via
 * `proxy_pass http://127.0.0.1:{internal_port}`, so each site on a host
 * needs a unique port in that range.
 *
 * Allocation strategy: scan in-use ports for the server, pick the lowest
 * unused port in the range. The DB has a partial unique index on
 * (server_id, internal_port) WHERE internal_port IS NOT NULL, so a
 * concurrent allocation collision will fail at insert time rather than
 * silently corrupt routing.
 */
final class InternalPortAllocator
{
    public const RANGE_START = 30000;

    public const RANGE_END = 39999;

    /**
     * Return the lowest unused internal port for the given server, or null
     * if the range is exhausted (10,000 non-PHP/static sites on a single
     * server — practically unreachable, but the caller should fall back
     * to a clear error rather than retry forever).
     */
    public function allocate(string $serverId): ?int
    {
        $takenPorts = Site::query()
            ->where('server_id', $serverId)
            ->whereNotNull('internal_port')
            ->pluck('internal_port')
            ->map(fn ($port) => (int) $port)
            ->all();

        $taken = array_flip($takenPorts);

        for ($port = self::RANGE_START; $port <= self::RANGE_END; $port++) {
            if (! isset($taken[$port])) {
                return $port;
            }
        }

        return null;
    }
}
