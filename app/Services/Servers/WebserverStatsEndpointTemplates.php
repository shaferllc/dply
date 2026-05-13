<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Single source of truth for the localhost-only stats endpoint configs
 * dply writes onto each webserver so the metrics agent can scrape it.
 *
 * Used by:
 *   - {@see \App\Jobs\SwitchServerWebserverJob} — drops these during a
 *     fresh install / switch-to flow.
 *   - {@see \App\Console\Commands\BackfillWebserverStatsEndpointsCommand}
 *     — drops them on existing servers that predate the observability
 *     feature.
 *
 * All endpoints bind to 127.0.0.1 so they never reach the public network.
 * Ports are dply-conventional: nginx :9091, apache :9092, traefik :9093
 * (traefik is configured via /etc/traefik/traefik.yml; see
 * {@see \App\Jobs\AddEdgeProxyJob::writeTraefikStaticConfig()}).
 */
final class WebserverStatsEndpointTemplates
{
    public const NGINX_PORT = 9091;

    public const APACHE_PORT = 9092;

    public const NGINX_CONF_PATH = '/etc/nginx/conf.d/dply-stub-status.conf';

    public const APACHE_CONF_PATH = '/etc/apache2/conf-available/dply-server-status.conf';

    public const APACHE_CONF_NAME = 'dply-server-status';

    /**
     * nginx server block exposing /nginx_status (ngx_http_stub_status_module).
     * The module is compiled into the Debian/Ubuntu nginx package by default —
     * no module install needed. `allow 127.0.0.1; deny all;` keeps it scoped
     * to the agent even though the listener is already localhost-only.
     */
    public static function nginxStubStatusConf(): string
    {
        $port = self::NGINX_PORT;

        return <<<CONF
server {
    listen 127.0.0.1:{$port} default_server;
    server_name _;
    access_log off;
    location = /nginx_status {
        stub_status on;
        allow 127.0.0.1;
        deny all;
    }
}
CONF;
    }

    /**
     * apache vhost exposing /server-status (mod_status). Returns a config
     * file body suitable for /etc/apache2/conf-available — apache picks it
     * up after `a2enconf dply-server-status`. mod_status must also be
     * enabled (`a2enmod status`); the backfill + install scripts do that
     * before writing this conf.
     */
    public static function apacheServerStatusConf(): string
    {
        $port = self::APACHE_PORT;

        return <<<CONF
Listen 127.0.0.1:{$port}
<VirtualHost 127.0.0.1:{$port}>
    ServerName dply-status
    <Location /server-status>
        SetHandler server-status
        Require ip 127.0.0.1
    </Location>
</VirtualHost>
CONF;
    }
}
