<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteWebserverConfigProfile;
use App\Support\SiteRedirectConfigSupport;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use App\Support\Sites\SiteAccessGateConfigSupport;
use App\Support\Servers\InstalledStack;
use App\Support\Sites\SiteManagedErrorPageSupport;
use App\Support\Sites\VmDockerSiteConfigSupport;

class NginxSiteConfigBuilder
{
    /**
     * The PHP-FPM version to point `fastcgi_pass` at. The bug this guards: a
     * site with no (or a stale) configured PHP version would fall back to a
     * hardcoded '8.3', producing `/run/php/php8.3-fpm.sock` — which doesn't
     * exist on a box dply provisioned with 8.4 → every request 502s. So we
     * trust what dply actually installed: use the site's configured version
     * only when it's among the installed set; otherwise the server's
     * provisioned version (installed_stack), then a last-resort default.
     */
    private function phpFpmVersion(Site $site): string
    {
        $server = $site->server;
        $installedPrimary = $server !== null ? InstalledStack::fromMeta($server)->phpVersion : null;
        $configured = $site->phpVersion();

        if ($configured !== null && $configured !== '') {
            $installed = $this->installedPhpVersions($site, $installedPrimary);
            if ($installed === [] || in_array($configured, $installed, true)) {
                return $configured;
            }
        }

        return ($installedPrimary !== null && $installedPrimary !== '') ? $installedPrimary : '8.3';
    }

    /**
     * PHP versions believed to exist on the box: the inventory list plus the
     * provisioned primary. Empty means "unknown" (don't second-guess the site).
     *
     * @return list<string>
     */
    private function installedPhpVersions(Site $site, ?string $primary): array
    {
        $server = $site->server;
        $versions = [];
        if ($server !== null) {
            foreach ((array) data_get($server->meta, 'php_inventory.installed_versions', []) as $v) {
                $id = (string) (is_array($v) ? ($v['version'] ?? $v['id'] ?? '') : $v);
                if ($id !== '') {
                    $versions[] = $id;
                }
            }
        }
        if ($primary !== null && $primary !== '' && ! in_array($primary, $versions, true)) {
            $versions[] = $primary;
        }

        return $versions;
    }
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
        if ($site->type === SiteType::Custom) {
            return '';
        }

        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers', 'accessGate']);
        $hostnames = collect($site->webserverHostnames());
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Nginx.');
        }

        if ($profile && $profile->isFullOverride() && trim((string) $profile->full_override_body) !== '') {
            return trim((string) $profile->full_override_body);
        }

        $names = $hostnames->implode(' ');
        $basename = $site->webserverConfigBasename();

        if (VmDockerSiteConfigSupport::applies($site)) {
            return $this->vmDockerProxyBlock($site, $basename, $names, $profile, $listenPort);
        }

        if ($site->isSuspended()) {
            return $this->suspendedBlock($site, $basename, $names);
        }

        // Worker-host sites never serve the deployed app — the code must not be
        // browsable. Serve the static "this runs workers" splash for every
        // request (rooted at workerStaticRoot, NOT the app docroot) on both HTTP
        // and HTTPS. Without this, nginx roots at the empty/absent app docroot
        // and returns 403. (Caddy already special-cases this; Nginx now matches.)
        if ($site->isWorkerSite()) {
            return $this->appendTlsServerBlocks($site, $this->workerBlock($site, $basename, $names));
        }

        $root = $site->effectiveDocumentRootForNginx();
        // Dedicated-pool PHP sites get their own version-free socket; anything
        // else (legacy/edge) keeps the shared per-version socket.
        $phpSock = $site->usesDedicatedPhpFpmPool()
            ? $site->phpFpmListenSocketPath()
            : str_replace(
                '{version}',
                $this->phpFpmVersion($site),
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

        if ($listenPort !== null) {
            return $this->rewriteForListenPort($config, $listenPort);
        }

        return $this->appendTlsServerBlocks($site, $config);
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
    /**
     * Static "this server runs workers" splash for a worker-host site, served
     * for every path (mirrors Caddy's worker block). Rooted at workerStaticRoot
     * so the deployed code is never browsable. TLS is layered on by
     * {@see appendTlsServerBlocks()} when the cert is ready.
     */
    protected function workerBlock(Site $site, string $basename, string $serverNames): string
    {
        $root = $site->workerStaticRoot();

        return <<<NGINX
# Managed by Dply — {$basename} (worker host — no web app)
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    root {$root};
    index index.html;
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

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
            $formGate = SiteAccessGateConfigSupport::nginxFragments($site, $root);
            $managedErrors = SiteManagedErrorPageSupport::nginxServerBlock($site);
            $proxyIntercept = SiteManagedErrorPageSupport::nginxProxyInterceptErrors();

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
{$managedErrors}{$layerPrefix}{$poolNote}{$redirectBlock}{$reverb}{$octaneBa['preamble']}{$formGate['preamble']}{$formGate['gate_locations']}{$formGate['error_page']}    location / {
{$octaneBa['location_slash_auth']}{$formGate['location_slash_auth']}        try_files \$uri @octane;
    }

    location @octane {
{$octaneBa['named_location_auth']}{$proxyIntercept}        proxy_http_version 1.1;
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
        // Per-site PHP ini overrides ride the same FastCGI seam as the cache
        // directives, so they land in the main php location AND every
        // basic-auth php fragment below.
        if ($site->type === SiteType::Php) {
            $fcgiEngine = app(SitePhpRuntimeDirectivesBuilder::class)->nginxDirectives($site).$fcgiEngine;
        }
        $phpBa = $this->nginxBasicAuthPhpFragments($site, $root, $phpSock, $fcgiEngine);
        $formGate = SiteAccessGateConfigSupport::nginxFragments($site, $root);
        $managedErrors = SiteManagedErrorPageSupport::nginxServerBlock($site);
        // With APP_DEBUG=true, let Laravel's own error page through instead of
        // masking app 5xx with the branded page (nginx 502s with no app response
        // still hit error_page).
        $fastcgiIntercept = SiteManagedErrorPageSupport::appDebugEnabled($site)
            ? "        fastcgi_intercept_errors off;\n"
            : SiteManagedErrorPageSupport::nginxFastcgiInterceptErrors();

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
{$managedErrors}{$layerPrefix}
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
{$poolNote}{$redirectBlock}{$reverbPlain}{$phpBa['preamble']}{$formGate['preamble']}{$formGate['gate_locations']}{$formGate['error_page']}{$phpBa['prefix_locations']}    location / {
{$phpBa['location_slash_auth']}{$formGate['location_slash_auth']}        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param REQUEST_ID \$request_id;
        fastcgi_pass unix:{$phpSock};
{$fastcgiIntercept}{$fcgiEngine}
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
        $formGate = SiteAccessGateConfigSupport::nginxFragments($site, $root);
        $managedErrors = SiteManagedErrorPageSupport::nginxServerBlock($site);

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
{$managedErrors}{$layerPrefix}{$openFile}{$redirectBlock}{$staticBa['preamble']}{$formGate['preamble']}{$formGate['gate_locations']}{$formGate['error_page']}{$staticBa['prefix_locations']}
    location / {
{$staticBa['location_slash_auth']}{$formGate['location_slash_auth']}        try_files \$uri \$uri/ =404;
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
        $formGate = SiteAccessGateConfigSupport::nginxFragments($site, $webRoot);
        $managedErrors = SiteManagedErrorPageSupport::nginxServerBlock($site);
        $proxyIntercept = SiteManagedErrorPageSupport::nginxProxyInterceptErrors();

        return <<<NGINX
# Managed by Dply — {$basename}
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$managedErrors}{$layerPrefix}{$redirectBlock}{$nodeBa['preamble']}{$formGate['preamble']}{$formGate['gate_locations']}{$formGate['error_page']}{$nodeBa['prefix_locations']}
    location / {
{$nodeBa['location_slash_auth']}{$formGate['location_slash_auth']}{$proxyIntercept}        proxy_http_version 1.1;
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

        // Two locations: the websocket path (client wss handshake) and the
        // Pusher HTTP API base `/apps/` so the server-side broadcaster can
        // publish events to Reverb through the same vhost (REVERB_HOST points
        // here). Both proxy to the local Reverb server.
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

    location ^~ /apps/ {
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
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
        if (SiteAccessGateConfigSupport::usesFormPasswordGate($site)) {
            return false;
        }

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
            fastcgi_param REQUEST_ID \$request_id;
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

    protected function vmDockerProxyBlock(
        Site $site,
        string $basename,
        string $names,
        ?SiteWebserverConfigProfile $profile,
        ?int $listenPort,
    ): string {
        if ($site->isSuspended()) {
            return $this->suspendedBlock($site, $basename, $names);
        }

        $port = VmDockerSiteConfigSupport::upstreamPort($site);
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

        $webRoot = rtrim($site->effectiveDocumentRootForNginx(), '/');
        $nodeBa = $this->nginxBasicAuthNodeFragments($site, $webRoot);
        $proxyCache = app(SiteCacheDirectivesBuilder::class)->nginxProxyDirectives($site);
        $managedErrors = SiteManagedErrorPageSupport::nginxServerBlock($site);
        $proxyIntercept = SiteManagedErrorPageSupport::nginxProxyInterceptErrors();

        $config = <<<NGINX
# Managed by Dply — {$basename} (vm docker)
server {
    listen 80;
    listen [::]:80;
    server_name {$names};
    access_log /var/log/nginx/{$basename}-access.log;
    error_log /var/log/nginx/{$basename}-error.log;
{$managedErrors}{$layerPrefix}{$redirectBlock}{$nodeBa['preamble']}{$nodeBa['prefix_locations']}
    location / {
{$nodeBa['location_slash_auth']}{$proxyIntercept}        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
{$proxyCache}        proxy_pass http://127.0.0.1:{$port};
    }
{$extraBlock}
}
NGINX;

        if ($listenPort !== null) {
            return $this->rewriteForListenPort($config, $listenPort);
        }

        return $this->appendTlsServerBlocks($site, $config);
    }

    /**
     * Duplicate the managed :80 server block for :443 when Let's Encrypt material
     * is installed so HTTPS requests hit the same auth and routing as HTTP.
     */
    protected function appendTlsServerBlocks(Site $site, string $config): string
    {
        if (! OpenLiteSpeedTlsPaths::siteExpectsTls($site) || ! OpenLiteSpeedTlsPaths::siteEdgeTlsFrontReady($site)) {
            return $config;
        }

        $paths = OpenLiteSpeedTlsPaths::resolve($site);
        if ($paths === null) {
            return $config;
        }

        $sslDirectives = "    ssl_certificate {$paths['certFile']};\n"
            ."    ssl_certificate_key {$paths['keyFile']};\n"
            ."    ssl_protocols TLSv1.2 TLSv1.3;\n";

        $tlsBlock = preg_replace(
            '/(\s*)listen 80;\s*\n\s*listen \[::\]:80;/',
            "$1listen 443 ssl;\n$1listen [::]:443 ssl;\n{$sslDirectives}",
            $config,
            1,
            $count
        );

        if ($count === 0 || $tlsBlock === null) {
            return $config;
        }

        // Enforce HTTP→HTTPS: replace the :80 app block with a slim redirect
        // that still serves the ACME http-01 challenge from the document root
        // so certbot renewals keep working. Falls back to serving the app on
        // both :80 and :443 if we can't parse server_name/root out of $config.
        $http80 = $this->httpsRedirectServerBlock($config);
        if ($http80 === null) {
            return $config."\n\n".$tlsBlock;
        }

        return $http80."\n\n".$tlsBlock;
    }

    /**
     * Build a minimal :80 server block that 301-redirects to HTTPS while still
     * serving `/.well-known/acme-challenge/` from the site's document root.
     * Returns null when server_name/root can't be extracted from $config.
     */
    protected function httpsRedirectServerBlock(string $config): ?string
    {
        if (! preg_match('/^\s*server_name\s+([^;]+);/m', $config, $sn)
            || ! preg_match('/^\s*root\s+([^;]+);/m', $config, $rt)) {
            return null;
        }

        $serverNames = trim($sn[1]);
        $root = trim($rt[1]);

        return <<<NGINX
# Managed by Dply — HTTP→HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name {$serverNames};

    location ^~ /.well-known/acme-challenge/ {
        root {$root};
        default_type "text/plain";
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;
    }
}
