<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Curated metadata for each webserver dply can run. Mirrors the shape of
 * {@see CacheEngineInfo} so the cache-engine-info-card partial can be reused
 * verbatim. URLs are first-party only; license + dates kept current at v1.
 */
final class WebserverEngineInfo
{
    /**
     * @return array<string, array{
     *   label: string,
     *   tagline: string,
     *   description: string,
     *   homepage_url: string,
     *   docs_url: string,
     *   license: string,
     *   maintainer: string,
     *   best_for: string,
     *   wire_protocol: string,
     *   first_released: string,
     * }>
     */
    public static function all(): array
    {
        return [
            'nginx' => [
                'label' => 'nginx',
                'tagline' => __('Event-driven webserver and reverse proxy.'),
                'description' => __('The de-facto standard webserver for PHP-FPM stacks. Event-driven worker model handles thousands of concurrent connections per process with predictable memory use. dply\'s default — every site config dply generates targets nginx directives first.'),
                'homepage_url' => 'https://nginx.org',
                'docs_url' => 'https://nginx.org/en/docs/',
                'license' => 'BSD-2-Clause',
                'maintainer' => 'F5 (formerly NGINX Inc.)',
                'best_for' => __('PHP-FPM, static files, reverse proxy to Node/Ruby/Octane upstreams. The pragmatic default.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3 (with the QUIC module)',
                'first_released' => '2004',
            ],
            'caddy' => [
                'label' => 'Caddy',
                'tagline' => __('Modern webserver with automatic HTTPS built in.'),
                'description' => __('Go-based webserver whose headline feature is zero-config automatic HTTPS — it provisions and renews Let\'s Encrypt / ZeroSSL certificates without certbot or any external glue. Configuration via Caddyfile (compact, opinionated) or JSON. Reverse-proxies to FastCGI / HTTP upstreams the same way nginx does.'),
                'homepage_url' => 'https://caddyserver.com',
                'docs_url' => 'https://caddyserver.com/docs/',
                'license' => 'Apache-2.0',
                'maintainer' => 'Stack Holdings GmbH',
                'best_for' => __('When operators want auto-HTTPS without managing certbot. Strong defaults; less ecosystem of modules than nginx.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3 (built-in)',
                'first_released' => '2015',
            ],
            'apache' => [
                'label' => 'Apache HTTP Server',
                'tagline' => __('The web\'s long-standing reference webserver.'),
                'description' => __('Module-rich webserver with the largest historical ecosystem (mod_rewrite, mod_security, mod_php, mod_proxy_fcgi, …). Per-request fork or threaded MPM workers — higher per-connection overhead than nginx/caddy but unmatched plugin coverage. .htaccess support is the single biggest reason teams still pick Apache.'),
                'homepage_url' => 'https://httpd.apache.org',
                'docs_url' => 'https://httpd.apache.org/docs/2.4/',
                'license' => 'Apache-2.0',
                'maintainer' => 'Apache Software Foundation',
                'best_for' => __('Legacy PHP apps that depend on .htaccess; environments where mod_security or specific Apache modules are required.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2 (mod_http2)',
                'first_released' => '1995',
            ],
            'openlitespeed' => [
                'label' => 'OpenLiteSpeed',
                'tagline' => __('LiteSpeed\'s open-source event-driven webserver.'),
                'description' => __('Open-source sibling of LiteSpeed Web Server. Event-driven (like nginx) but ships with its own LSAPI for PHP — faster than FastCGI on benchmarks but requires per-PHP-version `lsphpXX` packages instead of standard php-fpm. Includes a built-in admin GUI on port 7080.'),
                'homepage_url' => 'https://openlitespeed.org',
                'docs_url' => 'https://openlitespeed.org/kb/',
                'license' => 'GPL-3.0',
                'maintainer' => 'LiteSpeed Technologies',
                'best_for' => __('PHP workloads where the LiteSpeed cache module matters. dply auto-installs the matching `lsphpXX` packages based on each site\'s PHP version during the switch.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3 (QUIC)',
                'first_released' => '2013',
            ],
            'traefik' => [
                'label' => 'Traefik',
                'tagline' => __('Cloud-native edge router and reverse proxy.'),
                'description' => __('Reverse proxy and load balancer that auto-discovers backends from Docker, Kubernetes, Consul, etc. Not a traditional webserver — it proxies to upstream application servers. dply runs Caddy as the per-site backend on ephemeral high ports and configures Traefik to route hosts to those backends; PHP, static, and node sites all work transparently. Native ACME support; YAML/TOML config; rich Prometheus metrics out of the box.'),
                'homepage_url' => 'https://traefik.io',
                'docs_url' => 'https://doc.traefik.io/traefik/',
                'license' => 'MIT',
                'maintainer' => 'Traefik Labs',
                'best_for' => __('Routing in front of containerized services and dynamic backends. dply installs Caddy as the per-site upstream automatically, so PHP-FPM and static-file sites work fine through Traefik.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3, gRPC, TCP/UDP',
                'first_released' => '2015',
            ],
            'haproxy' => [
                'label' => 'HAProxy',
                'tagline' => __('Battle-tested L4/L7 load balancer and reverse proxy.'),
                'description' => __('High-performance reverse proxy and load balancer used at the edge of large-scale production sites. Not a traditional webserver — it proxies to upstream application servers. dply runs Caddy as the per-site backend on ephemeral high ports and configures HAProxy to route hosts to those backends; PHP, static, and node sites all work transparently. Powerful ACL/routing language; strong observability via the runtime API and stats socket; native TLS termination.'),
                'homepage_url' => 'https://www.haproxy.org',
                'docs_url' => 'https://docs.haproxy.org',
                'license' => 'GPL-2.0',
                'maintainer' => 'HAProxy Technologies',
                'best_for' => __('Operators who want a battle-tested edge proxy with explicit hand-written routing rules. dply manages the haproxy.cfg generation so you don\'t have to template-rebuild it on every site change.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, TCP, UDP',
                'first_released' => '2001',
            ],
            'envoy' => [
                'label' => 'Envoy',
                'tagline' => __('Cloud-native L7 proxy and service mesh data plane.'),
                'description' => 'CNCF-graduated proxy designed for dynamic configuration, rich observability, and gRPC/HTTP routing. In dply\'s edge-proxy model it sits on :80 and forwards to Caddy site backends on high ports — same pattern as Traefik and HAProxy.',
                'homepage_url' => 'https://www.envoyproxy.io',
                'docs_url' => 'https://www.envoyproxy.io/docs/envoy/latest/',
                'license' => 'Apache-2.0',
                'maintainer' => 'CNCF / Envoy maintainers',
                'best_for' => __('Teams already standardized on Envoy for edge routing, xDS, or mesh-adjacent patterns who want dply-managed host→backend wiring.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3, gRPC, TCP',
                'first_released' => '2016',
            ],
            'openresty' => [
                'label' => 'OpenResty',
                'tagline' => __('nginx + LuaJIT programmable edge.'),
                'description' => 'nginx extended with Lua for programmable routing, auth, and rate limits at the edge. Distinct from choosing nginx as the primary webserver — OpenResty would front :80 and proxy to Caddy backends when dply\'s edge-proxy install ships.',
                'homepage_url' => 'https://openresty.org',
                'docs_url' => 'https://openresty.org/en/getting-started.html',
                'license' => 'BSD-2-Clause',
                'maintainer' => 'OpenResty Inc.',
                'best_for' => __('Operators who want Lua-driven edge logic (JWT gates, custom ACLs, A/B routing) without hand-rolling nginx configs on every site change.'),
                'wire_protocol' => 'HTTP/1.1, HTTP/2, HTTP/3 (with modules)',
                'first_released' => '2009',
            ],
        ];
    }

    public static function for(string $engine): array
    {
        return self::all()[$engine] ?? [
            'label' => ucfirst($engine),
            'tagline' => '',
            'description' => '',
            'homepage_url' => '',
            'docs_url' => '',
            'license' => '—',
            'maintainer' => '—',
            'best_for' => '',
            'wire_protocol' => '—',
            'first_released' => '—',
        ];
    }
}
