<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;

class CaddySiteConfigBuilder
{
    public function build(Site $site, ?int $listenPort = null): string
    {
        $site->loadMissing('domains');

        $hostnames = $site->domains->pluck('hostname')->filter()->unique()->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Caddy.');
        }

        $hosts = $listenPort === null ? $hostnames->implode(', ') : ':'.$listenPort;
        $root = $site->effectiveDocumentRoot();
        $basename = $site->webserverConfigBasename();
        $phpSock = str_replace(
            '{version}',
            $site->php_version ?? '8.3',
            config('sites.php_fpm_socket')
        );

        return match ($site->type) {
            SiteType::Php => <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    php_fastcgi unix//{$phpSock}
    file_server
}
CADDY,
            SiteType::Static => <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    file_server
}
CADDY,
            SiteType::Node => <<<CADDY
{$hosts} {
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    reverse_proxy 127.0.0.1:{$site->app_port}
}
CADDY,
        };
    }
}
