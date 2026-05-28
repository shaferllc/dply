<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Collection;

final class ServerTags
{
    /**
     * @return list<string>
     */
    public static function forServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $tags = $meta['tags'] ?? [];

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $tag): string => is_string($tag) ? trim($tag) : '',
            $tags,
        )));
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return list<string>
     */
    public static function collectFromServers(Collection $servers): array
    {
        $tags = [];
        foreach ($servers as $server) {
            foreach (self::forServer($server) as $tag) {
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        $keys = array_keys($tags);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }

    public static function hasTag(Server $server, string $tag): bool
    {
        $needle = trim($tag);
        if ($needle === '') {
            return true;
        }

        return in_array($needle, self::forServer($server), true);
    }
}
