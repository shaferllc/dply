<?php

namespace App\Services\Deploy;

use App\Models\Site;

class LocalPublishedPortAllocator
{
    public function reserve(Site $site, int $preferredPort = 8080): int
    {
        $taken = Site::query()
            ->where('id', '!=', $site->getKey())
            ->pluck('meta')
            ->map(fn (mixed $meta): mixed => is_array($meta) ? data_get($meta, 'runtime_target.publication.port') : null)
            ->filter(fn (mixed $port): bool => is_int($port) || ctype_digit((string) $port))
            ->map(fn (mixed $port): int => (int) $port)
            ->unique()
            ->values()
            ->all();

        $port = max(1024, $preferredPort);
        while (in_array($port, $taken, true)) {
            $port++;
        }

        return $port;
    }
}
