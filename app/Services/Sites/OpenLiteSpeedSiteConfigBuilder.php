<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Support\SiteRedirectConfigSupport;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
use Illuminate\Support\Collection;

class OpenLiteSpeedSiteConfigBuilder
{
    /**
     * Per-vhost config (vhconf.conf contents). For signature symmetry with the
     * other engine builders we accept a `$listenPort` argument, but OLS routes
     * port handling through the listener block in httpd_config.conf — the
     * per-vhost file itself is portless. So the parameter is intentionally
     * unused here; callers in the switch-flow path get a port mismatch via the
     * httpd_config the OpenLiteSpeedHttpdConfigBuilder emits instead.
     */
    public function build(Site $site, ?int $listenPort = null): string
    {
        if ($site->type === SiteType::Custom) {
            return '';
        }

        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers']);

        $hostnames = collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing OpenLiteSpeed.');
        }

        $vhostRoot = rtrim($site->effectiveRepositoryPath(), '/');

        if ($site->isSuspended()) {
            return $this->buildSuspendedVhost($site, $hostnames, $vhostRoot);
        }

        $root = $site->effectiveDocumentRoot();

        if ($site->type === SiteType::Php && $site->octane_port) {
            return $this->buildPhpOctaneProxy($site, $hostnames, $vhostRoot);
        }

        $authRealms = $this->olsBasicAuthRealmBlocks($site);
        $authPrefixContexts = $this->olsBasicAuthPrefixContexts($site);
        $rootAuthLines = $this->olsBasicAuthRootContextLines($site);
        $lscacheBlock = app(SiteCacheDirectivesBuilder::class)->olsLscacheBlock($site);
        $tlsPaths = $listenPort === null ? OpenLiteSpeedTlsPaths::resolve($site) : null;
        $tlsBlock = $tlsPaths !== null ? OpenLiteSpeedTlsPaths::vhsslBlock($tlsPaths)."\n" : '';
        $lsapiHandler = $this->olsLsapiHandlerName($site);

        return match ($site->type) {
            SiteType::Php => <<<CONF
docRoot                   \$VH_ROOT/public/
vhDomain                  {$hostnames->implode(',')}
vhAliases                 www.{$hostnames->first()}
adminEmails               root@localhost
enableGzip                1
{$tlsBlock}index  {
  useServer               0
  indexFiles              index.html, index.php
}
errorlog \$VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog \$VH_ROOT/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
scripthandler  {
  add                     lsapi:{$lsapiHandler} php
}
{$lscacheBlock}{$authRealms}{$this->rewriteBlock($site, null, $tlsPaths !== null)}
{$authPrefixContexts}context / {
  location                \$VH_ROOT/public/
  allowBrowse             1
{$rootAuthLines}}
CONF,
            SiteType::Static => <<<CONF
docRoot                   {$root}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
{$tlsBlock}index  {
  useServer               0
  indexFiles              index.html
}
{$lscacheBlock}{$authRealms}{$this->rewriteBlock($site, null, $tlsPaths !== null)}
{$authPrefixContexts}context / {
  location                {$root}
  allowBrowse             1
{$rootAuthLines}}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF,
            SiteType::Node => <<<CONF
docRoot                   {$vhostRoot}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
{$tlsBlock}{$this->rewriteBlock($site, $site->app_port, $tlsPaths !== null)}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF,
            SiteType::Custom => '',
        };
    }

    private function buildSuspendedVhost(Site $site, Collection $hostnames, string $vhostRoot): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<CONF
docRoot                   {$root}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
index  {
  useServer               0
  indexFiles              index.html
}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF;
    }

    private function buildPhpOctaneProxy(Site $site, Collection $hostnames, string $vhostRoot): string
    {
        $oct = (int) $site->octane_port;
        $authRealms = $this->olsBasicAuthRealmBlocks($site);
        $authPrefixContexts = $this->olsBasicAuthPrefixContexts($site);
        $rootAuthLines = $this->olsBasicAuthRootContextLines($site);
        $tlsPaths = OpenLiteSpeedTlsPaths::resolve($site);
        $tlsBlock = $tlsPaths !== null ? OpenLiteSpeedTlsPaths::vhsslBlock($tlsPaths)."\n" : '';
        // OLS Octane proxies every request through the vhost rewrite block. To
        // gate that with basic auth we wrap the proxy in a context / { ... }
        // and let OLS evaluate auth before the rewrite fires. Per-prefix
        // contexts (when the runtime supports them) sit before the catch-all so
        // longer prefixes win OLS's longest-match resolution.
        $rootContext = $rootAuthLines !== ''
            ? "context / {\n  allowBrowse             1\n{$rootAuthLines}}\n"
            : '';

        return <<<CONF
docRoot                   {$vhostRoot}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
{$tlsBlock}{$authRealms}{$this->rewriteBlock($site, $oct, $tlsPaths !== null)}
{$authPrefixContexts}{$rootContext}errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF;
    }

    private function configName(Site $site): string
    {
        return str_replace(['.', '-'], '_', $site->webserverConfigBasename());
    }

    /**
     * Server-level extProcessor name in httpd_config.conf for this site's PHP version.
     */
    private function olsLsapiHandlerName(Site $site): string
    {
        $version = str_replace('.', '', (string) ($site->phpVersion() ?? '83'));

        return 'lsphp'.$version;
    }

    /**
     * Vhost-level realm declarations — one per enforceable basic-auth path group.
     * OLS resolves `userDB { location <htpasswd> }` against the htpasswd files
     * we already write at provision time, so no per-realm hash filtering is needed.
     */
    protected function olsBasicAuthRealmBlocks(Site $site): string
    {
        $groups = $this->olsBasicAuthPathGroups($site);
        if ($groups === []) {
            return '';
        }

        $out = '';
        foreach ($groups as $g) {
            $out .= "realm {$g['realm']} {\n"
                ."  userDB {\n"
                ."    location              {$g['users_file']}\n"
                ."    userNameField         User-Name\n"
                ."    maxCacheSize          200\n"
                ."    cacheTimeout          60\n"
                ."  }\n"
                ."}\n";
        }

        return $out;
    }

    /**
     * Per-prefix `context /admin { ... }` blocks for path-prefix basic auth.
     * Each block proxies to the same handler the parent vhost uses for static
     * content (or PHP, depending on type). Keep these BEFORE the catch-all
     * `context /` so longer prefixes win OLS's longest-match resolution.
     */
    protected function olsBasicAuthPrefixContexts(Site $site): string
    {
        if (! $site->basicAuthSupportsPathPrefixes()) {
            return '';
        }

        $groups = $this->olsBasicAuthPathGroups($site);
        $prefixGroups = array_filter($groups, fn (array $g): bool => $g['path'] !== '/');
        if ($prefixGroups === []) {
            return '';
        }

        $out = '';
        foreach ($prefixGroups as $g) {
            $out .= "context {$g['path']} {\n"
                ."  allowBrowse             1\n"
                ."  realm                   {$g['realm']}\n"
                ."  authName                Restricted\n"
                ."  required                valid-user\n"
                ."}\n";
        }

        return $out;
    }

    /**
     * Lines to splice INSIDE the `context / { ... }` block of the parent vhost
     * when the root path itself is gated. Returns empty when not gated so the
     * vhost stays unchanged. Includes a leading newline because the splice
     * lives just before the closing brace.
     */
    protected function olsBasicAuthRootContextLines(Site $site): string
    {
        $groups = $this->olsBasicAuthPathGroups($site);
        $rootGroup = null;
        foreach ($groups as $g) {
            if ($g['path'] === '/') {
                $rootGroup = $g;
                break;
            }
        }
        if ($rootGroup === null) {
            return '';
        }

        return "  realm                   {$rootGroup['realm']}\n"
            ."  authName                Restricted\n"
            ."  required                valid-user\n";
    }

    /**
     * @return array<int, array{path: string, realm: string, users_file: string}>
     */
    private function olsBasicAuthPathGroups(Site $site): array
    {
        $users = $site->enforceableBasicAuthUsers();
        if ($users->isEmpty()) {
            return [];
        }

        $groups = [];
        $configName = $this->configName($site);

        if ($users->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $groups[] = [
                'path' => '/',
                'realm' => 'dply_'.$configName.'_root',
                'users_file' => $site->basicAuthHtpasswdPathForNormalizedPath('/'),
            ];
        }

        if ($site->basicAuthSupportsPathPrefixes()) {
            $paths = $users
                ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
                ->unique()
                ->filter(fn (string $p): bool => $p !== '/')
                ->sortByDesc(fn (string $p): int => strlen($p))
                ->values();

            foreach ($paths as $locPath) {
                $hash = substr(hash('sha256', SiteBasicAuthUser::normalizePath($locPath)), 0, 16);
                $groups[] = [
                    'path' => $locPath,
                    'realm' => 'dply_'.$configName.'_'.$hash,
                    'users_file' => $site->basicAuthHtpasswdPathForNormalizedPath($locPath),
                ];
            }
        }

        return $groups;
    }

    private function rewriteBlock(Site $site, ?int $proxyPort = null, bool $forceHttpsRedirect = false): string
    {
        $rules = collect();

        // Block any path with a leading dot segment (`/.env`, `/.git`, etc.),
        // exempting `/.well-known/` for ACME challenges. Matches the deny
        // behavior the Nginx, Apache, and Caddy builders inject by default.
        // The [F,L] flags return 403 and stop processing — placed FIRST so
        // it short-circuits before any redirect or proxy rule below.
        $rules->push('RewriteRule ^/?\.(?!well-known)[^/]+ - [F,L]');

        if ($forceHttpsRedirect) {
            $rules->push('RewriteCond %{HTTPS} !=on');
            $rules->push('RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]');
        }

        $olsHeaderNote = false;
        foreach ($site->redirects->sortBy('sort_order') as $redirect) {
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $redirect->from_path);
            if ($from === '') {
                continue;
            }
            $fromTail = ltrim($from, '/');
            $kind = $redirect->kind instanceof SiteRedirectKind ? $redirect->kind : SiteRedirectKind::Http;
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $redirect->to_url);
                if ($to === '') {
                    continue;
                }
                $rules->push(sprintf('RewriteRule ^%s$ %s [L]', preg_quote($fromTail, '#'), $to));

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
            if (SiteRedirectConfigSupport::normalizeResponseHeaders($redirect->response_headers ?? null) !== []) {
                $olsHeaderNote = true;
            }
            $rules->push(sprintf('RewriteRule ^%s$ %s [R=%d,L]', preg_quote($fromTail, '#'), $to, $code));
        }
        if ($olsHeaderNote) {
            $rules->prepend('# Dply: custom response headers on HTTP redirects are not applied by Open LiteSpeed; use application headers or nginx/Caddy/Apache as the edge.');
        }

        if ($site->type === SiteType::Php && $site->shouldProxyReverbInWebserver()) {
            $ws = trim($site->reverbWebSocketPath(), '/');
            $rp = $site->reverbLocalPort();
            $rules->push(sprintf(
                'RewriteRule ^%s(.*)$ http://127.0.0.1:%d/%s$1 [P,L]',
                preg_quote($ws, '/'),
                $rp,
                $ws
            ));
        }

        if ($proxyPort !== null) {
            $rules->push(sprintf('RewriteRule ^(.*)$ http://127.0.0.1:%d/$1 [P,L]', $proxyPort));
        }

        if ($rules->isEmpty()) {
            return '';
        }

        return <<<CONF
rewrite  {
  enable                  1
  rules                   <<<END_rules
{$rules->implode("\n")}
END_rules
}
CONF;
    }
}
