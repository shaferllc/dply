<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Resolves (engine, on-disk config path) → upstream documentation URL.
 *
 * Lookups walk a per-engine list of (pattern, url, label) tuples in
 * declaration order; the first matching entry wins. Patterns are bare
 * file paths or regexes wrapped in `~…~` so per-site files (vhconf.conf,
 * site-available fragments) can match a single rule.
 *
 * Used by the config editor blade to surface a "Docs" link next to the
 * selected file path so operators don't have to context-switch to a
 * search engine when figuring out an unfamiliar directive.
 */
class WebserverConfigDocLinks
{
    /**
     * @var array<string, list<array{pattern: string, url: string, label: string, description: string, role?: string}>>
     */
    private const RULES = [
        'openlitespeed' => [
            [
                'pattern' => '/usr/local/lsws/conf/httpd_config.conf',
                'url' => 'https://openlitespeed.org/kb/main-configuration/',
                'label' => 'OpenLiteSpeed — main config reference',
                'description' => 'Server-wide config: listeners, vhost templates + members, ExtApps (LSAPI/FastCGI workers), the global LSCache module, tuning. Edits here apply to every site on the server. dply regenerates this on each webserver switch.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/usr/local/lsws/conf/vhosts/[^/]+/vhconf\.conf$~',
                'url' => 'https://openlitespeed.org/kb/virtual-host-configuration/',
                'label' => 'OpenLiteSpeed — virtual host config reference',
                'description' => 'Per-site OLS vhost: docRoot, index files, log paths, per-vhost cache rules, scripthandler (which PHP version), rewrites. dply rewrites this on every Site Apply — edits are durable only until the next provisioner run.',
                'role' => 'vhost',
            ],
            [
                'pattern' => '~^/usr/local/lsws/conf/templates/[^/]+\.conf$~',
                'url' => 'https://openlitespeed.org/kb/virtual-host-templates/',
                'label' => 'OpenLiteSpeed — virtual host templates',
                'description' => 'Reusable vhost template a `vhTemplate` block in httpd_config.conf points members at. Changes apply to every member site sharing this template.',
                'role' => 'template',
            ],
            [
                'pattern' => '/usr/local/lsws/conf/admin/admin_config.conf',
                'url' => 'https://openlitespeed.org/kb/admin-console-configuration/',
                'label' => 'OpenLiteSpeed — admin console config',
                'description' => 'Bind address, TLS, and access control for the OLS admin web console (default :7080). dply doesn\'t use the admin console — only edit if you\'ve enabled it manually.',
                'role' => 'admin',
            ],
            [
                'pattern' => '/usr/local/lsws/conf/mime.properties',
                'url' => 'https://openlitespeed.org/kb/mime-properties/',
                'label' => 'OpenLiteSpeed — MIME properties',
                'description' => 'Extension → MIME type map used when OLS sends a Content-Type header. Add entries for new file types your sites serve directly.',
                'role' => 'snippet',
            ],
        ],
        'nginx' => [
            [
                'pattern' => '~^/etc/nginx/conf\.d/.*dply-engine-http-cache.*\.conf$~',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_cache',
                'label' => 'nginx — proxy_cache',
                'description' => 'Global HTTP cache (proxy_cache) rules in front of PHP and app upstreams. Managed by dply for engine-level caching.',
                'role' => 'cache',
            ],
            [
                'pattern' => '~^/etc/nginx/conf\.d/.*dply-stub-status.*\.conf$~',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_stub_status_module.html',
                'label' => 'nginx — stub_status',
                'description' => 'Internal stub_status endpoint scraped by the dply metrics agent. Not for public traffic.',
                'role' => 'metrics',
            ],
            [
                'pattern' => '~^/etc/nginx/modules-(available|enabled)/~',
                'url' => 'https://docs.nginx.com/nginx/admin-guide/dynamic-modules/dynamic-modules/',
                'label' => 'nginx — dynamic modules',
                'description' => 'Loadable modules installed via libnginx-mod-* packages and enabled with load_module.',
            ],
            [
                'pattern' => '~^/etc/nginx/sites-available/dply-[0-9a-z]+-~',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_core_module.html',
                'label' => 'nginx — dply site vhost',
                'description' => 'dply-managed site vhost — server_name, SSL, and PHP-FPM routing. Regenerated on Site Apply.',
                'role' => 'vhost',
            ],
            [
                'pattern' => '/etc/nginx/sites-available/default',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_core_module.html',
                'label' => 'nginx — default site',
                'description' => 'Stock default site. Often unused on dply servers — confirm nothing serves from it before editing.',
                'role' => 'vhost',
            ],
            [
                'pattern' => '/etc/nginx/sites-available/dply',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_core_module.html',
                'label' => 'nginx — dply shared snippet',
                'description' => 'Shared dply nginx include or legacy catch-all. Check impact on all sites before changing.',
                'role' => 'snippet',
            ],
            [
                'pattern' => '/etc/nginx/nginx.conf',
                'url' => 'https://nginx.org/en/docs/beginners_guide.html',
                'label' => 'nginx — beginner\'s guide',
                'description' => 'Top-level nginx config: worker processes, events block, http defaults, includes for sites-enabled / conf.d. dply rewrites this on each webserver switch.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/etc/nginx/sites-(available|enabled)/~',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_core_module.html',
                'label' => 'nginx — http core module (server / location)',
                'description' => 'Per-site server block — listen, server_name, location, fastcgi_pass. dply rewrites this on each Site Apply.',
                'role' => 'vhost',
            ],
            [
                'pattern' => '~^/etc/nginx/conf\.d/~',
                'url' => 'https://nginx.org/en/docs/http/ngx_http_core_module.html',
                'label' => 'nginx — global http snippet',
                'description' => 'Auto-loaded global snippets (e.g. dply\'s `dply-stub-status.conf` for the metrics agent). Affects every site.',
                'role' => 'snippet',
            ],
        ],
        'apache' => [
            [
                'pattern' => '/etc/apache2/apache2.conf',
                'url' => 'https://httpd.apache.org/docs/current/configuring.html',
                'label' => 'Apache — main config reference',
                'description' => 'Top-level Apache config: MPM, includes, server-wide defaults. dply rewrites this on each webserver switch.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/etc/apache2/(sites-available|sites-enabled)/~',
                'url' => 'https://httpd.apache.org/docs/current/vhosts/',
                'label' => 'Apache — virtual host reference',
                'description' => 'Per-site VirtualHost block — ServerName, DocumentRoot, ProxyPass to PHP-FPM, Directory rules. dply rewrites this on each Site Apply.',
                'role' => 'vhost',
            ],
            [
                'pattern' => '~^/etc/apache2/(conf-available|conf-enabled)/~',
                'url' => 'https://httpd.apache.org/docs/current/mod/',
                'label' => 'Apache — global conf snippet',
                'description' => 'Server-wide conf snippet auto-included by apache2.conf. dply uses these for stub_status / security headers.',
                'role' => 'snippet',
            ],
            [
                'pattern' => '~^/etc/apache2/mods-(available|enabled)/~',
                'url' => 'https://httpd.apache.org/docs/current/mod/',
                'label' => 'Apache — module config',
                'description' => 'Per-module config (e.g. proxy_fcgi, http2). Use `a2enmod` / `a2dismod` rather than editing the symlinks under mods-enabled directly.',
                'role' => 'module',
            ],
            [
                'pattern' => '/etc/apache2/ports.conf',
                'url' => 'https://httpd.apache.org/docs/current/bind.html',
                'label' => 'Apache — Listen ports',
                'description' => 'Top-level `Listen` directives controlling which ports apache2 binds. dply manages these via the switch flow.',
                'role' => 'ports',
            ],
        ],
        'caddy' => [
            [
                'pattern' => '/etc/caddy/Caddyfile',
                'url' => 'https://caddyserver.com/docs/caddyfile',
                'label' => 'Caddy — Caddyfile reference',
                'description' => 'Top-level Caddyfile: global options, snippets, and `import` lines pulling in per-site fragments from sites-enabled. dply rewrites this on each webserver switch.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/etc/caddy/sites-(available|enabled)/~',
                'url' => 'https://caddyserver.com/docs/caddyfile/concepts',
                'label' => 'Caddy — site blocks',
                'description' => 'Per-site Caddyfile fragment — host matcher, reverse_proxy to PHP-FPM or HAProxy/Traefik backend, TLS settings. dply rewrites this on each Site Apply.',
                'role' => 'vhost',
            ],
        ],
        'traefik' => [
            [
                'pattern' => '/etc/traefik/traefik.yml',
                'url' => 'https://doc.traefik.io/traefik/reference/static-configuration/file/',
                'label' => 'Traefik — static config reference',
                'description' => 'Traefik static config: entryPoints (port bindings), providers, metrics, API, certificatesResolvers. Changes require a restart, not just reload.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/etc/traefik/dynamic/~',
                'url' => 'https://doc.traefik.io/traefik/reference/dynamic-configuration/file/',
                'label' => 'Traefik — dynamic config reference',
                'description' => 'Hot-reloaded routing config: routers, services, middlewares, TLS options. Traefik watches this directory and applies edits without restart.',
                'role' => 'dynamic',
            ],
        ],
        'haproxy' => [
            [
                'pattern' => '/etc/haproxy/haproxy.cfg',
                'url' => 'https://docs.haproxy.org/3.0/configuration.html',
                'label' => 'HAProxy — configuration manual',
                'description' => 'Main HAProxy config: global, defaults, frontends, backends. dply rewrites this on each webserver switch.',
                'role' => 'main',
            ],
            [
                'pattern' => '~^/etc/haproxy/conf\.d/~',
                'url' => 'https://docs.haproxy.org/3.0/configuration.html',
                'label' => 'HAProxy — split config fragment',
                'description' => 'Optional split-config fragment loaded alongside haproxy.cfg. Use to keep dply-managed bulk separate from operator-customised tweaks.',
                'role' => 'snippet',
            ],
        ],
    ];

    /**
     * Top-level "what is this engine?" doc link, shown when no per-path
     * rule matches. Keeps the operator from staring at a blank "Docs"
     * affordance for unusual files.
     *
     * @var array<string, array{url: string, label: string}>
     */
    private const FALLBACK = [
        'openlitespeed' => ['url' => 'https://openlitespeed.org/kb/', 'label' => 'OpenLiteSpeed — knowledge base'],
        'nginx' => ['url' => 'https://nginx.org/en/docs/', 'label' => 'nginx — documentation index'],
        'apache' => ['url' => 'https://httpd.apache.org/docs/current/', 'label' => 'Apache — documentation index'],
        'caddy' => ['url' => 'https://caddyserver.com/docs/', 'label' => 'Caddy — documentation index'],
        'traefik' => ['url' => 'https://doc.traefik.io/traefik/', 'label' => 'Traefik — documentation index'],
        'haproxy' => ['url' => 'https://docs.haproxy.org/', 'label' => 'HAProxy — documentation index'],
    ];

    /**
     * @return array{url: string, label: string}|null
     */
    public function resolve(string $engine, string $path): ?array
    {
        foreach (self::RULES[$engine] ?? [] as $rule) {
            if ($this->ruleMatches($rule, $path)) {
                return ['url' => $rule['url'], 'label' => $rule['label']];
            }
        }

        return self::FALLBACK[$engine] ?? null;
    }

    /**
     * Short prose explaining what the file is and when an operator would
     * edit it. Surfaced inline in the file picker so the operator doesn't
     * have to open the docs link just to know whether to touch a file.
     */
    public function describe(string $engine, string $path): ?string
    {
        foreach (self::RULES[$engine] ?? [] as $rule) {
            if ($this->ruleMatches($rule, $path)) {
                return $rule['description'];
            }
        }

        return null;
    }

    /**
     * Short role slug for file-picker badges (vhost, main, snippet, …).
     */
    public function roleFor(string $engine, string $path): ?string
    {
        foreach (self::RULES[$engine] ?? [] as $rule) {
            if ($this->ruleMatches($rule, $path)) {
                $role = $rule['role'] ?? null;

                return is_string($role) && $role !== '' ? $role : null;
            }
        }

        return null;
    }

    /**
     * @param  array{pattern: string}  $rule
     */
    private function ruleMatches(array $rule, string $path): bool
    {
        $pattern = $rule['pattern'];

        return str_starts_with($pattern, '~')
            ? preg_match($pattern, $path) === 1
            : $pattern === $path;
    }
}
