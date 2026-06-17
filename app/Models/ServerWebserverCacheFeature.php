<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * Per (server, webserver) install state for the webserver's NATIVE cache module.
 *
 * Varnish is a separate HTTP-front daemon and lives on `server_cache_services`
 * with engine='varnish'; this table is only for caches that ship inside (or
 * extend) the chosen webserver — nginx zone sizes, Apache mod_cache, Caddy
 * souin, OLS LSCache. The row is created lazily the first time a site enables
 * a cache method that needs it.
 */
class ServerWebserverCacheFeature extends Model
{
    use HasUlids;

    public const WEBSERVER_NGINX = 'nginx';

    public const WEBSERVER_APACHE = 'apache';

    public const WEBSERVER_CADDY = 'caddy';

    public const WEBSERVER_OLS = 'openlitespeed';

    /** @var list<string> */
    public const WEBSERVERS = [self::WEBSERVER_NGINX, self::WEBSERVER_APACHE, self::WEBSERVER_CADDY, self::WEBSERVER_OLS];

    protected $table = 'server_webserver_cache_features';

    protected $fillable = [
        'server_id',
        'webserver',
        'nginx_fcgi_zone_size_mb',
        'nginx_proxy_zone_size_mb',
        'nginx_zone_max_size_gb',
        'nginx_zone_inactive_minutes',
        'apache_mod_cache_enabled',
        'caddy_souin_built',
        'caddy_souin_version',
        'ols_lscache_module_present',
        'last_probed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nginx_fcgi_zone_size_mb' => 'integer',
            'nginx_proxy_zone_size_mb' => 'integer',
            'nginx_zone_max_size_gb' => 'integer',
            'nginx_zone_inactive_minutes' => 'integer',
            'apache_mod_cache_enabled' => 'boolean',
            'caddy_souin_built' => 'boolean',
            'ols_lscache_module_present' => 'boolean',
            'last_probed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /**
     * Fetch or create the feature row for (server, webserver). Defaults match
     * the historical hardcoded values from config('sites.nginx_engine_*') so
     * the first call after the migration produces the same on-disk config the
     * provisioner emitted before.
     */
    public static function findOrCreateFor(string $serverId, string $webserver): self
    {
        /** @var self $row */
        $row = self::query()->firstOrCreate(
            ['server_id' => $serverId, 'webserver' => $webserver],
            [
                'nginx_fcgi_zone_size_mb' => 100,
                'nginx_proxy_zone_size_mb' => 100,
                'nginx_zone_max_size_gb' => 2,
                'nginx_zone_inactive_minutes' => 60,
            ],
        );

        return $row;
    }
}
