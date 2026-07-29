<?php

declare(strict_types=1);

namespace App\Services\Sites\Concerns;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\SiteCacheDirectivesBuilder;
use App\Services\Sites\SitePhpRuntimeDirectivesBuilder;
use App\Support\SiteRedirectConfigSupport;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use App\Support\Sites\SiteAccessGateConfigSupport;
use App\Support\Sites\SiteManagedErrorPageSupport;
use App\Support\Sites\VmDockerSiteConfigSupport;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsNginxServerBlocks
{


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

        // One :443 server block per certificate group, so hostnames needing
        // different certs (a custom domain's own cert + the shared *.zone
        // wildcard) each present the right one via SNI. A site with a single
        // covering cert yields one group — identical to the old single block.
        $groups = OpenLiteSpeedTlsPaths::resolveCertGroups($site);
        if ($groups === []) {
            return $config;
        }

        $tlsBlocks = [];
        foreach ($groups as $group) {
            if (($group['hostnames'] ?? []) === []) {
                continue;
            }

            $sslDirectives = "    ssl_certificate {$group['certFile']};\n"
                ."    ssl_certificate_key {$group['keyFile']};\n"
                ."    ssl_protocols TLSv1.2 TLSv1.3;\n";

            $block = preg_replace(
                '/(\s*)listen 80;\s*\n\s*listen \[::\]:80;/',
                "$1listen 443 ssl;\n$1listen [::]:443 ssl;\n{$sslDirectives}",
                $config,
                1,
                $count
            );
            if ($count === 0 || $block === null) {
                continue;
            }

            // Scope this block to just its group's hostnames; SNI picks the block
            // (and therefore the cert) matching the requested name.
            $scoped = preg_replace(
                '/^(\s*server_name\s+)[^;]+;/m',
                '${1}'.implode(' ', $group['hostnames']).';',
                $block,
                1
            );
            $tlsBlocks[] = $scoped ?? $block;
        }

        if ($tlsBlocks === []) {
            return $config;
        }

        $tlsConfig = implode("\n\n", $tlsBlocks);

        // Enforce HTTP→HTTPS: replace the :80 app block with a slim redirect
        // that still serves the ACME http-01 challenge from the document root
        // so certbot renewals keep working. Falls back to serving the app on
        // both :80 and :443 if we can't parse server_name/root out of $config.
        $http80 = $this->httpsRedirectServerBlock($config);
        if ($http80 === null) {
            return $config."\n\n".$tlsConfig;
        }

        return $http80."\n\n".$tlsConfig;
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
