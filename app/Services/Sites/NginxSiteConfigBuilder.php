<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteWebserverConfigProfile;
use App\Support\SiteRedirectConfigSupport;

class NginxSiteConfigBuilder
{
    /**
     * Full vhost config. Pass a profile for layered includes and main snippet; omit for legacy nginx_extra_raw-only behavior.
     *
     * `$listenPort` produces a minimal HTTP-only vhost bound to that port instead of :80/:443 — used by the
     * webserver-switch flow to validate the new daemon on :8080 alongside the production webserver on :80.
     * In listen-port mode TLS plumbing (`listen 443 ssl`, `ssl_certificate*`, HSTS) is stripped because the
     * test port has no real certificate; the test only proves the daemon parses + serves on the alternate
     * port, not that it can negotiate TLS.
     */
    public function build(Site $site, ?SiteWebserverConfigProfile $profile = null, ?int $listenPort = null): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers']);
        $hostnames = collect($site->webserverHostnames());
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Nginx.');
        }

        if ($profile && $profile->isFullOverride() && trim((string) $profile->full_override_body) !== '') {
            return trim((string) $profile->full_override_body);
        }

        $names = $hostnames->implode(' ');
        $basename = $site->nginxConfigBasename();

        if ($site->isSuspended()) {
            return $this->suspendedBlock($site, $basename, $names);
        }

        $root = $site->effectiveDocumentRootForNginx();
        $phpSock = str_replace(
            '{version}',
            $site->phpVersion() ?? '8.3',
            config('sites.php_fpm_socket')
        );

        $redirects = $site->redirects->sortBy('sort_order')->values();
        $redirectBlock = '';
        foreach ($redirects as $r) {
            $kind = $r->kind instanceof SiteRedirectKind ? $r->kind : SiteRedirectKind::Http;
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $r->from_path);
            if ($from === '') {
                continue;
            }
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $r->to_url);
                if ($to === '') {
                    continue;
                }
                $pattern = '^'.preg_quote($from, '#').'$';
                $repl = SiteRedirectConfigSupport::escapeNginxRewriteReplacement($to);
                $redirectBlock .= "    rewrite {$pattern} {$repl} last;\n";

                continue;
            }
            $to = $this->escapeNginxDoubleQuoted((string) $r->to_url);
            $code = (int) $r->status_code;
            if ($to === '' || ! in_array($code, SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes(), true)) {
                continue;
            }
            $headers = SiteRedirectConfigSupport::normalizeResponseHeaders($r->response_headers ?? null);
            if ($headers !== []) {
                $redirectBlock .= "    location = {$from} {\n";
                foreach ($headers as $h) {
                    $hv = SiteRedirectConfigSupport::escapeNginxHeaderDirectiveValue($h['value']);
                    $redirectBlock .= '        add_header '.$h['name'].' "'.$hv."\" always;\n";
                }
                $redirectBlock .= "        return {$code} \"{$to}\";\n    }\n";
            } else {
                $redirectBlock .= "    location = {$from} { return {$code} \"{$to}\"; }\n";
            }
        }

        $useLayerIncludes = $profile && $profile->mode === SiteWebserverConfigProfile::MODE_LAYERED;
        $mainSource = $profile ? ($profile->main_snippet_body ?? $site->nginx_extra_raw) : $site->nginx_extra_raw;
        $extra = trim((string) ($mainSource ?? ''));
        $layerPrefix = '';
        if ($useLayerIncludes) {
            $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
            $layerPrefix = "    include {$base}/before/*.conf;\n";
        }
        if ($useLayerIncludes) {
            $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;
            $mainBlock = $extra !== '' ? "\n    ".str_replace("\n", "\n    ", $extra)."\n" : "\n";
            $extraBlock = $mainBlock."    include {$base}/after/*.conf;\n";
        } else {
            $extraBlock = $extra !== '' ? "\n    ".$extra."\n" : '';
        }

        $site->loadMissing('server');
        $poolUser = $site->server ? $site->effectiveSystemUser($site->server) : trim((string) ($site->php_fpm_user ?? ''));
        $poolNote = $poolUser !== ''
            ? "\n    # php-fpm pool user (configure pool on server): {$poolUser}\n"
            : '';

        $config = match ($site->type) {
            SiteType::Php => $this->phpBlock($basename, $names, $root, $phpSock, $redirectBlock, $layerPrefix, $extraBlock, $poolNote, $site),
            SiteType::Static => $this->staticBlock($basename, $names, $root, $redirectBlock, $layerPrefix, $extraBlock, $site),
            SiteType::Node => $this->nodeBlock($basename, $names, $this->resolveUpstreamPort($site), $redirectBlock, $layerPrefix, $extraBlock, $site),
            SiteType::Custom => null,
        };

        if ($config === null) {
            return '';
        }

        return $listenPort === null ? $config : $this->rewriteForListenPort($config, $listenPort);
    }

    /**
     * Mutate a built vhost into a port-test variant:
     *   - `listen 80` and `listen [::]:80` → single `listen <port>` (drop IPv6 dual bind)
     *   - Remove TLS bind lines (`listen 443 ssl`, `listen [::]:443 ssl`, http2/http3 variants)
     *   - Remove `ssl_certificate` / `ssl_certificate_key` / `ssl_*` directives so the rewritten config
     *     loads without requiring cert paths the test daemon won't have.
     *   - Remove HSTS / TLS-only headers since the test port serves HTTP.
     *
     * Idempotent and best-effort. Operates only on directive patterns nginx writes in its standard
     * Debian/Ubuntu layout; sites with handwritten weird directives may need post-cutover cleanup.
     */
    private function rewriteForListenPort(string $config, int $listenPort): string
    {
        // Collapse the IPv4 + IPv6 listen pair into a single port-only listen.
        $config = preg_replace('/^\s*listen\s+80\s*;.*$/m', '    listen '.$listenPort.';', $config);
        $config = preg_replace('/^\s*listen\s+\[::\]:80\s*;.*$/m', '', $config);

        // Strip TLS binds (443 + http2/http3 + reuseport + ssl).
        $config = preg_replace('/^\s*listen\s+\[?::\]?:?443[^\n]*;.*$/m', '', $config);
        $config = preg_replace('/^\s*listen\s+443[^\n]*;.*$/m', '', $config);

        // Strip SSL plumbing — every directive starting with ssl_, plus the well-known HSTS add_header.
        $config = preg_replace('/^\s*ssl_[a-z_]+\s+[^;]+;\s*$/m', '', $config);
        $config = preg_replace('/^\s*add_header\s+Strict-Transport-Security[^;]+;\s*$/m', '', $config);

        // Collapse runs of blank lines the stripping leaves behind so the result still reads cleanly.
        return preg_replace("/\n{3,}/", "\n\n", $config);
    }

    /**
     * Resolve the upstream port for non-PHP/static sites.
     *
     * Prefers the new `internal_port` column (allocated from 30000–39999
     * by InternalPortAllocator and unique per server) and falls back to
     * the legacy `app_port` (which sites created before the runtime-
     * agnostic columns landed still carry). The 3000 default is the last
     * resort for sites that have neither — a typical Node convention.
     */
    private function resolveUpstreamPort(Site $site): int
    {
        if ($site->internal_port !== null && $site->internal_port > 0) {
            return (int) $site->internal_port;
        }

        if ($site->app_port !== null && $site->app_port > 0) {
            return (int) $site->app_port;
        }

        return 3000;
    }

    /**
     * SHA-256 of managed vhost without snippets or layered includes (used for “core changed” warnings).
     */
    public function managedCoreHash(Site $site): string
    {
        $site = clone $site;
        $site->nginx_extra_raw = null;

        return hash('sha256', $this->build($site, null));
    }

    /**
     * Static-only vhost serving {@see Site::suspendedStaticRoot()} (no PHP, proxy, or redirects).
     */
    protected function suspendedBlock(Site $site, string $basename, string $serverNames): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<NGINX
# Managed by Dply — {$basename} (suspended)
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    protected function phpBlock(
        string $basename,
        string $serverNames,
        string $root,
        string $phpSock,
        string $redirectBlock,
        string $layerPrefix,
        string $extraBlock,
        string $poolNote,
        Site $site
    ): string {
        if ($site->octane_port) {
            $port = (int) $site->octane_port;
            $reverb = $this->reverbProxyLocationBlock($site);
            $octaneProxyCache = app(SiteCacheDirectivesBuilder::class)->nginxProxyDirectives($site);
            $octaneBa = $this->nginxBasicAuthOctaneFragments($site, $root);

            return <<<NGINX
# Managed by Dply — {$basename} (Laravel Octane)
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.php index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$layerPrefix}{$poolNote}{$redirectBlock}{$reverb}{$octaneBa['preamble']}    location / {
{$octaneBa['location_slash_auth']}        try_files \$uri @octane;
    }

    location @octane {
{$octaneBa['named_location_auth']}        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
{$octaneProxyCache}        proxy_pass http://127.0.0.1:{$port};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
        }

        $reverbPlain = $this->reverbProxyLocationBlock($site);
        $fcgiEngine = $site->type === SiteType::Php
            ? app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site)
            : '';
        $phpBa = $this->nginxBasicAuthPhpFragments($site, $root, $phpSock, $fcgiEngine);

        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.php index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$layerPrefix}
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
{$poolNote}{$redirectBlock}{$reverbPlain}{$phpBa['preamble']}{$phpBa['prefix_locations']}    location / {
{$phpBa['location_slash_auth']}        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$phpSock};
{$fcgiEngine}
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
    }

    protected function staticBlock(
        string $basename,
        string $serverNames,
        string $root,
        string $redirectBlock,
        string $layerPrefix,
        string $extraBlock,
        Site $site
    ): string {
        $openFile = app(SiteCacheDirectivesBuilder::class)->nginxOpenFileCacheBlock($site);
        $staticBa = $this->nginxBasicAuthStaticFragments($site, $root);

        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$layerPrefix}{$openFile}{$redirectBlock}{$staticBa['preamble']}{$staticBa['prefix_locations']}
    location / {
{$staticBa['location_slash_auth']}        try_files \$uri \$uri/ =404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{$extraBlock}
}
NGINX;
    }

    protected function nodeBlock(
        string $basename,
        string $serverNames,
        int $port,
        string $redirectBlock,
        string $layerPrefix,
        string $extraBlock,
        Site $site
    ): string {
        $proxyCache = app(SiteCacheDirectivesBuilder::class)->nginxProxyDirectives($site);
        $webRoot = rtrim($site->effectiveDocumentRootForNginx(), '/');
        $nodeBa = $this->nginxBasicAuthNodeFragments($site, $webRoot);

        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$layerPrefix}{$redirectBlock}{$nodeBa['preamble']}{$nodeBa['prefix_locations']}
    location / {
{$nodeBa['location_slash_auth']}        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
{$proxyCache}        proxy_pass http://127.0.0.1:{$port};
    }
{$extraBlock}
}
NGINX;
    }

    protected function reverbProxyLocationBlock(Site $site): string
    {
        if (! $site->shouldProxyReverbInWebserver()) {
            return '';
        }

        $port = $site->reverbLocalPort();
        $loc = $site->reverbWebSocketPath();

        return <<<NGINX
    location ^~ {$loc} {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_pass http://127.0.0.1:{$port};
    }

NGINX;
    }

    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthPhpFragments(Site $site, string $root, string $phpSock, string $fcgiEngine): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $prefix = $site->basicAuthSupportsPathPrefixes()
            ? $this->nginxBasicAuthPhpPrefixLocations($site, $phpSock, $fcgiEngine)
            : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => $prefix, 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthStaticFragments(Site $site, string $root): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $prefix = $site->basicAuthSupportsPathPrefixes()
            ? $this->nginxBasicAuthStaticPrefixLocations($site)
            : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => $prefix, 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthNodeFragments(Site $site, string $webRoot): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $webRoot !== '' ? $this->nginxBasicAuthAcmeChallengeBlock($webRoot) : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => '', 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, location_slash_auth: string, named_location_auth: string}
     */
    protected function nginxBasicAuthOctaneFragments(Site $site, string $root): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'location_slash_auth' => '', 'named_location_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $auth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $auth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return [
            'preamble' => $preamble,
            'location_slash_auth' => $auth,
            'named_location_auth' => $auth,
        ];
    }

    protected function nginxBasicAuthEnabled(Site $site): bool
    {
        return $site->enforceableBasicAuthUsers()->isNotEmpty();
    }

    protected function nginxBasicAuthAcmeChallengeBlock(string $root): string
    {
        return <<<NGINX
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        default_type "text/plain";
        root {$root};
        try_files \$uri =404;
    }

NGINX;
    }

    protected function nginxBasicAuthDirectives(string $htpasswdAbsolutePath): string
    {
        return "        auth_basic \"Restricted\";\n        auth_basic_user_file {$htpasswdAbsolutePath};\n";
    }

    protected function nginxBasicAuthPhpPrefixLocations(Site $site, string $phpSock, string $fcgiEngine): string
    {
        $paths = $site->enforceableBasicAuthUsers()
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->filter(fn (string $p): bool => $p !== '/')
            ->sortByDesc(fn (string $p): int => strlen($p))
            ->values();

        $out = '';
        foreach ($paths as $locPath) {
            $has = $site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath);
            if (! $has) {
                continue;
            }
            $escaped = $this->escapeNginxLocationPrefix($locPath);
            $htpasswd = $site->basicAuthHtpasswdPathForNormalizedPath($locPath);
            $auth = $this->nginxBasicAuthDirectives($htpasswd);
            $out .= <<<NGINX
    location ^~ {$escaped} {
{$auth}        try_files \$uri \$uri/ /index.php?\$query_string;
        location ~ \.php\$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:{$phpSock};
{$fcgiEngine}        }
    }

NGINX;
        }

        return $out;
    }

    protected function nginxBasicAuthStaticPrefixLocations(Site $site): string
    {
        $paths = $site->enforceableBasicAuthUsers()
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->filter(fn (string $p): bool => $p !== '/')
            ->sortByDesc(fn (string $p): int => strlen($p))
            ->values();

        $out = '';
        foreach ($paths as $locPath) {
            $has = $site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath);
            if (! $has) {
                continue;
            }
            $escaped = $this->escapeNginxLocationPrefix($locPath);
            $htpasswd = $site->basicAuthHtpasswdPathForNormalizedPath($locPath);
            $auth = $this->nginxBasicAuthDirectives($htpasswd);
            $out .= <<<NGINX
    location ^~ {$escaped} {
{$auth}        try_files \$uri \$uri/ =404;
    }

NGINX;
        }

        return $out;
    }

    protected function escapeNginxLocationPrefix(string $path): string
    {
        $p = SiteBasicAuthUser::normalizePath($path);
        if ($p === '/') {
            return '/';
        }

        return $p;
    }

    protected function escapeNginxDoubleQuoted(string $url): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $url);
    }
}
