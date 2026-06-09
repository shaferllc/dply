<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Services\Servers\ServerPhpManager;

/**
 * Shared helpers for VM form-password gate webserver directives.
 */
final class SiteAccessGateConfigSupport
{
    public static function usesFormPasswordGate(Site $site): bool
    {
        $site->loadMissing(['accessGate', 'basicAuthUsers']);

        return $site->usesFormPasswordGate();
    }

    public static function resolvePhpSocket(Site $site): string
    {
        $site->loadMissing('server');

        // Form-gate PHP runs through the same dedicated pool socket as the app.
        if ($site->usesDedicatedPhpFpmPool()) {
            return $site->phpFpmListenSocketPath();
        }

        if ($site->server !== null) {
            return str_replace(
                '{version}',
                app(ServerPhpManager::class)->resolveCaddyPhpVersion($site->server, $site->phpVersion()),
                (string) config('sites.php_fpm_socket', '/run/php/php{version}-fpm.sock'),
            );
        }

        return str_replace(
            '{version}',
            $site->phpVersion() ?? '8.3',
            (string) config('sites.php_fpm_socket', '/run/php/php{version}-fpm.sock'),
        );
    }

    private static function nginxGateFastcgiBlock(string $script, string $phpSock, bool $verifyRoute = false): string
    {
        $routeParam = $verifyRoute
            ? "\n        fastcgi_param DPLY_ACCESS_ROUTE verify;"
            : '';

        return <<<NGINX
        include fastcgi.conf;
        fastcgi_param SCRIPT_FILENAME {$script};{$routeParam}
        fastcgi_pass unix:{$phpSock};

NGINX;
    }

    /**
     * @return array{preamble: string, gate_locations: string, location_slash_auth: string, error_page: string}
     */
    public static function nginxFragments(Site $site, string $acmeRoot = ''): array
    {
        if (! self::usesFormPasswordGate($site)) {
            return [
                'preamble' => '',
                'gate_locations' => '',
                'location_slash_auth' => '',
                'error_page' => '',
            ];
        }

        $phpSock = self::resolvePhpSocket($site);
        $script = $site->accessGateScriptPathOnHost();
        $preamble = $acmeRoot !== ''
            ? self::nginxAcmeBypassBlock($acmeRoot)
            : '';

        $verifyFastcgi = self::nginxGateFastcgiBlock($script, $phpSock, true);
        $accessFastcgi = self::nginxGateFastcgiBlock($script, $phpSock);

        $gateLocations = <<<NGINX
    location = /__dply/access/verify {
        internal;
{$verifyFastcgi}    }

    location ^~ /__dply/access {
{$accessFastcgi}    }

NGINX;

        $locationSlashAuth = <<<'NGINX'
        auth_request /__dply/access/verify;
NGINX;

        $errorPage = <<<'NGINX'
    error_page 401 = @dply_vm_access_login;

    location @dply_vm_access_login {
        return 302 /__dply/access?return=$request_uri;
    }

NGINX;

        return [
            'preamble' => $preamble,
            'gate_locations' => $gateLocations,
            'location_slash_auth' => $locationSlashAuth,
            'error_page' => $errorPage,
        ];
    }

    public static function caddyBlocks(Site $site): string
    {
        if (! self::usesFormPasswordGate($site)) {
            return '';
        }

        $phpSock = self::resolvePhpSocket($site);
        $gateDir = $site->accessGateStorageDirectoryOnHost();

        return <<<CADDY
    handle /__dply/access* {
        root * {$gateDir}
        rewrite * /index.php
        php_fastcgi unix/{$phpSock}
    }
    forward_auth /__dply/access/verify {
        uri /__dply/access/verify
    }

CADDY;
    }

    /**
     * @return array<int, array{middleware: string, address: string}>
     */
    public static function traefikFormGateGroups(Site $site, string $basename): array
    {
        if (! self::usesFormPasswordGate($site)) {
            return [];
        }

        $backendPort = (int) ($site->internal_port ?? $site->app_port ?? 80);

        return [[
            'middleware' => $basename.'-form-gate',
            'address' => 'http://127.0.0.1:'.$backendPort.'/__dply/access/verify',
        ]];
    }

    /**
     * @return array{directory: string, locations: string, rewrite: string}
     */
    public static function apacheBlocks(Site $site): array
    {
        if (! self::usesFormPasswordGate($site)) {
            return [
                'directory' => '        Require all granted'."\n",
                'locations' => '',
                'rewrite' => '',
            ];
        }

        $phpSock = self::resolvePhpSocket($site);
        $script = $site->accessGateScriptPathOnHost();

        return [
            'directory' => <<<'APACHE'
        AuthType None
        Require all granted

APACHE,
            'locations' => <<<APACHE
    <Location "/__dply/access">
        Require all granted
        SetHandler "proxy:unix:{$phpSock}|fcgi://localhost{$script}"
    </Location>
    <Location "/__dply/access/verify">
        Require all granted
        SetHandler "proxy:unix:{$phpSock}|fcgi://localhost{$script}"
        SetEnv DPLY_ACCESS_ROUTE verify
    </Location>

APACHE,
            'rewrite' => <<<'APACHE'
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/__dply/access [NC]
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteCond %{HTTP:Cookie} !__dply_vm_access= [NC]
    RewriteRule ^ /__dply/access?return=%{REQUEST_URI} [R=302,L]

APACHE,
        ];
    }

    private static function nginxAcmeBypassBlock(string $root): string
    {
        return <<<NGINX
    location ^~ /.well-known/acme-challenge/ {
        auth_request off;
        default_type "text/plain";
        root {$root};
        try_files \$uri =404;
    }

NGINX;
    }
}
