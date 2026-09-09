<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\Server;
use App\Models\Site;

/**
 * Workers must run the same PHP as the origin site. The provisioner otherwise
 * falls back to 8.3 (Ubuntu’s distro default), and Composer fails on a ^8.4 lock.
 */
class WorkerPhpVersion
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function applyToMeta(array $meta, Server $source, ?Site $originSite = null): array
    {
        $php = $this->forWorker($source, $originSite);
        if ($php !== null) {
            $meta['php_version'] = $php;
            $meta['default_php_version'] = $php;
        }

        return $meta;
    }

    public function forWorker(Server $source, ?Site $originSite = null): ?string
    {
        foreach ([
            $originSite?->phpVersion(),
            $originSite?->runtime_version,
            data_get($source->meta, 'default_php_version'),
            data_get($source->meta, 'php_version'),
        ] as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (! $source->exists) {
            return null;
        }

        $fromSite = $source->sites()
            ->where('runtime', 'php')
            ->whereNotNull('runtime_version')
            ->orderByDesc('updated_at')
            ->value('runtime_version');

        return $this->normalize(is_string($fromSite) ? $fromSite : null);
    }

    public function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (preg_match('/(\d+\.\d+)/', (string) $value, $match) !== 1) {
            return null;
        }

        return $match[1] === '0.0' ? null : $match[1];
    }
}
