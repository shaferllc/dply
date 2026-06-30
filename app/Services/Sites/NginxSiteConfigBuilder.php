<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Services\Sites\Concerns\BuildsNginxBasicAuthFragments;
use App\Services\Sites\Concerns\BuildsNginxServerBlocks;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteWebserverConfigProfile;
use App\Support\Servers\InstalledStack;
use App\Support\SiteRedirectConfigSupport;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use App\Support\Sites\SiteAccessGateConfigSupport;
use App\Support\Sites\SiteManagedErrorPageSupport;
use App\Support\Sites\VmDockerSiteConfigSupport;

class NginxSiteConfigBuilder
{
    use BuildsNginxBasicAuthFragments;
    use BuildsNginxServerBlocks;

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
    public function build(Site $site, ?SiteWebserverConfigProfile $profile = null, ?int $listenPort = null, bool $httpOnly = false): string
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
            // Serve the suspended page on :443 too (with the site's existing
            // cert) so HTTPS visitors — and Cloudflare Full/Strict origins,
            // which connect to the origin over TLS — get the suspended page
            // instead of a 52x "origin down" error. Listen-port mode stays
            // HTTP-only.
            $suspended = $this->suspendedBlock($site, $basename, $names);

            return $listenPort !== null
                ? $this->rewriteForListenPort($suspended, $listenPort)
                : ($httpOnly ? $suspended : $this->appendTlsServerBlocks($site, $suspended));
        }

        // Worker-host sites never serve the deployed app — the code must not be
        // browsable. Serve the static "this runs workers" splash for every
        // request (rooted at workerStaticRoot, NOT the app docroot) on both HTTP
        // and HTTPS. Without this, nginx roots at the empty/absent app docroot
        // and returns 403. (Caddy already special-cases this; Nginx now matches.)
        if ($site->isWorkerSite()) {
            $workerConfig = $this->workerBlock($site, $basename, $names);

            return $httpOnly ? $workerConfig : $this->appendTlsServerBlocks($site, $workerConfig);
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

        return $httpOnly ? $config : $this->appendTlsServerBlocks($site, $config);
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


}
