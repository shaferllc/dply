<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Managed 500-series error pages written under {@see Site::managedErrorPagesRoot()}
 * during provision and referenced from generated webserver configs.
 */
final class SiteManagedErrorPageSupport
{
    public const ERROR_URI = '/__dply__/errors/500.html';

    public const ERROR_FILENAME = '500.html';

    public static function root(Site $site): string
    {
        return $site->managedErrorPagesRoot();
    }

    public static function filePath(Site $site): string
    {
        return rtrim(self::root($site), '/').'/'.self::ERROR_FILENAME;
    }

    public static function nginxServerBlock(Site $site): string
    {
        $uri = self::ERROR_URI;
        $file = self::filePath($site);

        return <<<NGINX
    error_page 500 502 503 504 {$uri};
    location = {$uri} {
        internal;
        alias {$file};
    }

NGINX;
    }

    public static function nginxProxyInterceptErrors(): string
    {
        return "        proxy_intercept_errors on;\n";
    }

    public static function nginxFastcgiInterceptErrors(): string
    {
        return "        fastcgi_intercept_errors on;\n";
    }

    public static function apacheVirtualHostBlock(Site $site): string
    {
        $uri = self::ERROR_URI;
        $file = self::filePath($site);

        return <<<APACHE
    ErrorDocument 500 {$uri}
    ErrorDocument 502 {$uri}
    ErrorDocument 503 {$uri}
    ErrorDocument 504 {$uri}
    Alias {$uri} {$file}

APACHE;
    }

    public static function apacheProxyErrorOverride(): string
    {
        return "    ProxyErrorOverride On\n";
    }

    public static function caddyBlock(Site $site): string
    {
        $root = self::root($site);

        return <<<CADDY
    handle_errors {
        @server_error expression {http.error.status_code} >= 500
        handle @server_error {
            rewrite * /500.html
            root * {$root}
            file_server
        }
    }

CADDY;
    }

    public static function openLiteSpeedBlock(Site $site): string
    {
        $uri = self::ERROR_URI;
        $root = self::root($site);

        return <<<CONF
errorpage 500 {
  url {$uri}
}
errorpage 502 {
  url {$uri}
}
errorpage 503 {
  url {$uri}
}
errorpage 504 {
  url {$uri}
}
context /__dply__/errors/ {
  location                {$root}/
  allowBrowse             1
  indexFiles              500.html
}

CONF;
    }
}
