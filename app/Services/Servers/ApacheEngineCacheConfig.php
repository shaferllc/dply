<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerWebserverCacheFeature;

/**
 * Apache server-level cache posture — browser Expires via mod_expires (site
 * apply) and optional mod_cache disk (flag stored for future v2 wiring).
 */
class ApacheEngineCacheConfig
{
    public const DISK_CACHE_PATH = '/var/cache/apache2/mod_cache_disk';

    /**
     * @return array{
     *     mod_expires_enabled: bool,
     *     mod_deflate_enabled: bool,
     *     apache_mod_cache_enabled: bool,
     *     disk_cache_path: string,
     * }
     */
    public function read(Server $server): array
    {
        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_APACHE,
        );

        $modules = app(ApacheModulesConfig::class)->read($server);
        $enabled = [];
        foreach ($modules['modules'] as $row) {
            if (($row['enabled'] ?? false) === true) {
                $enabled[$row['name']] = true;
            }
        }

        return [
            'mod_expires_enabled' => isset($enabled['expires']),
            'mod_deflate_enabled' => isset($enabled['deflate']),
            'apache_mod_cache_enabled' => (bool) $feature->apache_mod_cache_enabled,
            'disk_cache_path' => self::DISK_CACHE_PATH,
        ];
    }

    public function saveModCacheFlag(Server $server, bool $enabled): void
    {
        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_APACHE,
        );
        $feature->apache_mod_cache_enabled = $enabled;
        $feature->save();
    }
}
