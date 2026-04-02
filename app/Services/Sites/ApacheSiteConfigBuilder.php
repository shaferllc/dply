<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;

class ApacheSiteConfigBuilder
{
    public function build(Site $site): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

        $hostnames = collect($site->webserverHostnames());
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Apache.');
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

        return match ($site->type) {
            SiteType::Php => <<<APACHE
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
