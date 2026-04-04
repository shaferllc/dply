<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Support\Collection;

class ApacheSiteConfigBuilder
{
    public function build(Site $site): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers']);

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
            $octBa = $this->apacheRootLocationBasicAuth($site);

            return <<<APACHE
# Managed by Dply — {$basename} (Laravel Octane)
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "http"
{$redirectLines}{$reverb}{$octBa}    ProxyPass / http://127.0.0.1:{$port}/
    ProxyPassReverse / http://127.0.0.1:{$port}/
</VirtualHost>
APACHE;
        }

        $reverbPhp = $site->type === SiteType::Php ? $this->reverbProxyDirectives($site) : '';
        $engineApache = $site->wantsEngineHttpCache() ? $this->apacheStaticAssetExpiresBlock() : '';
        $phpBa = $this->apacheDirectoryAndPrefixLocations($site, $root);
        $staticBa = $this->apacheDirectoryAndPrefixLocations($site, $root);
        $nodeBa = $this->apacheRootLocationBasicAuth($site);

        return match ($site->type) {
            SiteType::Php => <<<APACHE
# Managed by Dply — {$basename}
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    DocumentRoot {$root}
    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
{$engineApache}{$redirectLines}{$reverbPhp}    <Directory {$root}>
        AllowOverride All
{$phpBa['directory']}
        Options FollowSymLinks
        DirectoryIndex index.php index.html
        FallbackResource /index.php
    </Directory>

{$phpBa['locations']}    <FilesMatch \.php$>
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
{$staticBa['directory']}
        Options FollowSymLinks
        DirectoryIndex index.html
    </Directory>

{$staticBa['locations']}
</VirtualHost>
APACHE,
            SiteType::Node => <<<APACHE
# Managed by Dply — {$basename}
<VirtualHost *:80>
    ServerName {$primary}
{$aliasLines}    ErrorLog \${APACHE_LOG_DIR}/{$basename}-error.log
    CustomLog \${APACHE_LOG_DIR}/{$basename}-access.log combined
    ProxyPreserveHost On
{$redirectLines}{$nodeBa}    ProxyPass / http://127.0.0.1:{$site->app_port}/
    ProxyPassReverse / http://127.0.0.1:{$site->app_port}/
</VirtualHost>
APACHE,
        };
    }

    /**
     * @return array{directory: string, locations: string}
     */
    protected function apacheDirectoryAndPrefixLocations(Site $site, string $documentRoot): array
    {
        $site->loadMissing('basicAuthUsers');
        if ($site->basicAuthUsers->isEmpty()) {
            return [
                'directory' => '        Require all granted'."\n",
                'locations' => '',
            ];
        }

        $directory = '        Require all granted'."\n";
        if ($site->basicAuthUsers->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $f = $site->basicAuthHtpasswdPathForNormalizedPath('/');
            $directory = <<<AUTH
        AuthType Basic
        AuthName "Restricted"
        AuthUserFile {$f}
        Require valid-user

AUTH;
        }

        $locations = '';
        $paths = $site->basicAuthUsers
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->filter(fn (string $p): bool => $p !== '/')
            ->sortByDesc(fn (string $p): int => strlen($p))
            ->values();

        foreach ($paths as $locPath) {
            if (! $site->basicAuthUsers->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath)) {
                continue;
            }
            $f = $site->basicAuthHtpasswdPathForNormalizedPath($locPath);
            $locations .= <<<APACHE
    <Location "{$locPath}">
        AuthType Basic
        AuthName "Restricted"
        AuthUserFile {$f}
        Require valid-user
    </Location>

APACHE;
        }

        return ['directory' => $directory, 'locations' => $locations];
    }

    protected function apacheRootLocationBasicAuth(Site $site): string
    {
        $site->loadMissing('basicAuthUsers');
        if (! $site->basicAuthUsers->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            return '';
        }

        $f = $site->basicAuthHtpasswdPathForNormalizedPath('/');

        return <<<APACHE
    <Location />
        AuthType Basic
        AuthName "Restricted"
        AuthUserFile {$f}
        Require valid-user
    </Location>

APACHE;
    }

    /**
     * When “engine HTTP cache” is enabled on Apache, apply browser caching for common static MIME types.
     * Full-page dynamic caching is nginx FastCGI cache; use nginx as the edge web server when possible.
     */
    private function apacheStaticAssetExpiresBlock(): string
    {
        return <<<'APACHE'
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 1 week"
        ExpiresByType application/javascript "access plus 1 week"
        ExpiresByType image/svg+xml "access plus 1 month"
        ExpiresByType image/png "access plus 1 month"
        ExpiresByType image/jpeg "access plus 1 month"
        ExpiresByType image/webp "access plus 1 month"
    </IfModule>

APACHE;
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
        $headerLines = [];
        $lines = [];
        foreach ($site->redirects->sortBy('sort_order') as $redirect) {
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $redirect->from_path);
            if ($from === '') {
                continue;
            }
            $pattern = '^'.preg_quote($from, '#').'$';
            $kind = $redirect->kind instanceof SiteRedirectKind ? $redirect->kind : SiteRedirectKind::Http;
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $redirect->to_url);
                if ($to === '') {
                    continue;
                }
                $sub = SiteRedirectConfigSupport::escapeApacheRewriteSubstitution($to);
                $lines[] = "    RewriteRule {$pattern} {$sub} [L]";

                continue;
            }
            $code = (int) $redirect->status_code;
            if (! in_array($code, SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes(), true)) {
                continue;
            }
            $to = trim((string) $redirect->to_url);
            if ($to === '') {
                continue;
            }
            $headers = SiteRedirectConfigSupport::normalizeResponseHeaders($redirect->response_headers ?? null);
            foreach ($headers as $h) {
                $hn = $h['name'];
                $hv = SiteRedirectConfigSupport::escapeApacheHeaderConfigValue($h['value']);
                $pathLit = $this->apacheExprRequestUriEquals($from);
                $headerLines[] = "    Header always set \"{$hn}\" \"{$hv}\" \"expr=%{REQUEST_URI} == {$pathLit}\"";
            }
            $sub = SiteRedirectConfigSupport::escapeApacheRewriteSubstitution($to);
            $lines[] = "    RewriteRule {$pattern} {$sub} [R={$code},L]";
        }

        $out = '';
        if ($headerLines !== []) {
            $out .= implode("\n", $headerLines)."\n";
        }
        if ($lines === []) {
            return $out !== '' ? $out."\n" : '';
        }

        return $out."    RewriteEngine On\n".implode("\n", $lines)."\n\n";
    }

    /**
     * Apache expr() string literal for REQUEST_URI comparison (path is sanitized, no quotes).
     */
    private function apacheExprRequestUriEquals(string $fromPath): string
    {
        $escaped = str_replace('\\', '\\\\', $fromPath);
        $escaped = str_replace('"', '\\"', $escaped);

        return '"'.$escaped.'"';
    }
}
