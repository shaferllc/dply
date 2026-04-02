<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Support\Collection;

class ApacheSiteConfigBuilder
{
    public function build(Site $site): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

        $hostnames = collect($site->webserverHostnames());
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Apache.');
        }

        if ($site->isSuspended()) {
            return $this->suspendedVirtualHost($site, $hostnames);
        }

        $primary = $hostnames->first();
        $aliases = $hostnames->skip(1)->values();
        $root = $site->effectiveDocumentRoot();
        $phpSock = str_replace(
            '{version}',
            $site->php_version ?? '8.3',
            config('sites.php_fpm_socket')
        );
        $basename = $site->webserverConfigBasename();
        $aliasLines = $aliases->isNotEmpty()
            ? '    ServerAlias '.$aliases->implode(' ')."\n"
            : '';
        $redirectLines = $this->redirectLines($site);

        if ($site->type === SiteType::Php && $site->octane_port) {
            $port = (int) $site->octane_port;
            $reverb = $this->reverbProxyDirectives($site);

            return <<<APACHE
# Managed by Dply — {$basename} (Laravel Octane)
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "http"
{$redirectLines}{$reverb}    ProxyPass / http://127.0.0.1:{$port}/
    ProxyPassReverse / http://127.0.0.1:{$port}/
</VirtualHost>
APACHE;
        }

        $reverbPhp = $site->type === SiteType::Php ? $this->reverbProxyDirectives($site) : '';

        return match ($site->type) {
            SiteType::Php => <<<APACHE
# Managed by Dply — {$basename}
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
{$redirectLines}{$reverbPhp}    <Directory {$root}>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
        DirectoryIndex index.php index.html
        FallbackResource /index.php
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$phpSock}|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
APACHE,
            SiteType::Static => <<<APACHE
# Managed by Dply — {$basename}
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined

{$redirectLines}    <Directory {$root}>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
        DirectoryIndex index.html
    </Directory>
</VirtualHost>
APACHE,
            SiteType::Node => <<<APACHE
# Managed by Dply — {$basename}
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
{$redirectLines}    ProxyPass / http://127.0.0.1:{$site->app_port}/
    ProxyPassReverse / http://127.0.0.1:{$site->app_port}/
</VirtualHost>
APACHE,
        };
    }

    private function suspendedVirtualHost(Site $site, Collection $hostnames): string
    {
        $primary = $hostnames->first();
        $aliases = $hostnames->skip(1)->values();
        $root = $site->suspendedStaticRoot();
        $basename = $site->webserverConfigBasename();
        $aliasLines = $aliases->isNotEmpty()
            ? '    ServerAlias '.$aliases->implode(' ')."\n"
            : '';

        return <<<APACHE
# Managed by Dply — {$basename} (suspended)
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined

    <Directory {$root}>
        AllowOverride None
        Require all granted
        Options FollowSymLinks
        DirectoryIndex index.html
    </Directory>
</VirtualHost>
APACHE;
    }

    private function reverbProxyDirectives(Site $site): string
    {
        if (! $site->shouldProxyReverbInWebserver()) {
            return '';
        }

        $path = $site->reverbWebSocketPath();
        $port = $site->reverbLocalPort();
        $upstream = 'http://127.0.0.1:'.$port.$path.'/';

        return '    ProxyPass '.$path.' '.$upstream."\n"
            .'    ProxyPassReverse '.$path.' '.$upstream."\n";
    }

    private function redirectLines(Site $site): string
    {
        if ($site->redirects->isEmpty()) {
            return '';
        }

        return $site->redirects
            ->map(fn ($redirect): string => sprintf(
                '    Redirect %d %s %s',
                $redirect->status_code,
                $redirect->from_path,
                $redirect->to_url,
            ))
            ->implode("\n")."\n\n";
    }
}
