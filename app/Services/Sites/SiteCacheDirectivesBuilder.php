<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Webserver-specific cache directive emitter for a single site.
 *
 * The four `*SiteConfigBuilder` classes call into this service for the cache
 * snippets they used to emit inline. The shape of `$site->cachingConfig()` is
 * the contract; this class is the only piece that translates that shape into
 * webserver syntax. Add a new webserver-native cache (Caddy souin, Apache
 * mod_cache, etc.) by adding a new method here — the builders stay thin.
 */
final class SiteCacheDirectivesBuilder
{
    /**
     * nginx FastCGI cache directives, scoped to a `location ~ \.php$` block.
     * Returns an empty string when the site doesn't have the `nginx_http`
     * method enabled.
     */
    public function nginxFastcgiDirectives(Site $site): string
    {
        if (! $site->hasCachingMethod('nginx_http')) {
            return '';
        }

        $cfg = $this->nginxFcgiOptions($site);
        $zone = config('sites.nginx_engine_fcgi_cache_zone');
        $ttl200 = $cfg['ttl_200'];
        $ttl404 = $cfg['ttl_404'];
        $minUses = $cfg['min_uses'];
        $bypass = $this->nginxBypassVars($site);

        return <<<NGINX
        fastcgi_cache {$zone};
        fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";
        fastcgi_cache_valid 200 {$ttl200};
        fastcgi_cache_valid 404 {$ttl404};
        fastcgi_cache_bypass {$bypass};
        fastcgi_no_cache {$bypass};
        fastcgi_cache_min_uses {$minUses};
        fastcgi_cache_use_stale error timeout updating http_500 http_503;
        add_header X-Dply-Engine-Cache \$upstream_cache_status;

NGINX;
    }

    /**
     * nginx proxy_cache directives — scoped to the upstream-proxying location
     * (Octane `@octane`, Node `location /`). Empty string when disabled.
     */
    public function nginxProxyDirectives(Site $site): string
    {
        if (! $site->hasCachingMethod('nginx_http')) {
            return '';
        }

        $cfg = $this->nginxProxyOptions($site);
        $zone = config('sites.nginx_engine_proxy_cache_zone');
        $ttl200 = $cfg['ttl_200'];
        $ttl404 = $cfg['ttl_404'];
        $bypass = $this->nginxBypassVars($site);

        return <<<NGINX
        proxy_cache {$zone};
        proxy_cache_key "\$scheme\$request_method\$host\$request_uri";
        proxy_cache_valid 200 {$ttl200};
        proxy_cache_valid 404 {$ttl404};
        proxy_cache_bypass {$bypass};
        proxy_cache_use_stale error timeout updating http_500 http_503;
        add_header X-Dply-Engine-Cache \$upstream_cache_status;

NGINX;
    }

    /**
     * nginx open_file_cache directives for static-site vhosts. Constant tuning
     * for v1 — operators can override via the layered webserver-config editor
     * if they need different values.
     */
    public function nginxOpenFileCacheBlock(Site $site): string
    {
        if (! $site->hasCachingMethod('nginx_http')) {
            return '';
        }

        return "    open_file_cache max=4000 inactive=30s;\n    open_file_cache_valid 45s;\n    open_file_cache_min_uses 2;\n";
    }

    /**
     * OpenLiteSpeed LSCache vhost block. Lives inside the `cache { ... }`
     * stanza in vhconf.conf — the builder splices this in after the
     * extprocessor + scripthandler blocks.
     */
    public function olsLscacheBlock(Site $site): string
    {
        if (! $site->hasCachingMethod('lscache')) {
            return '';
        }

        $cfg = $site->cachingConfig();
        $expire = 120;
        if (isset($cfg['lscache']['ttl']) && is_numeric($cfg['lscache']['ttl'])) {
            $expire = max(1, (int) $cfg['lscache']['ttl']);
        }

        // qsCache=1 + reqCookieCache=1 + respCookieCache=1 are the standard
        // "cache everything safe" defaults. Per-rule excludes (admin paths,
        // logged-in users) are a v2 follow-up — operators who need them today
        // can drop them via the layered webserver-config editor.
        return <<<CONF
cache  {
  enableCache             1
  qsCache                 1
  reqCookieCache          1
  respCookieCache         1
  expireInSeconds         {$expire}
}

CONF;
    }

    /**
     * @return array{ttl_200: string, ttl_404: string, min_uses: int}
     */
    private function nginxFcgiOptions(Site $site): array
    {
        $cfg = $site->cachingConfig();
        $fcgi = $cfg['nginx_http']['fcgi'] ?? [];

        return [
            'ttl_200' => $this->ttl((string) ($fcgi['ttl_200'] ?? '60m'), '60m'),
            'ttl_404' => $this->ttl((string) ($fcgi['ttl_404'] ?? '10m'), '10m'),
            'min_uses' => max(1, (int) ($fcgi['min_uses'] ?? 1)),
        ];
    }

    /**
     * @return array{ttl_200: string, ttl_404: string}
     */
    private function nginxProxyOptions(Site $site): array
    {
        $cfg = $site->cachingConfig();
        $proxy = $cfg['nginx_http']['proxy'] ?? [];

        return [
            'ttl_200' => $this->ttl((string) ($proxy['ttl_200'] ?? '60m'), '60m'),
            'ttl_404' => $this->ttl((string) ($proxy['ttl_404'] ?? '10m'), '10m'),
        ];
    }

    /**
     * Validate an nginx TTL token (`60m`, `1h`, `30s`, `2d`). Falls back to
     * the default when the operator-supplied value doesn't match nginx's
     * time-suffix grammar — emitting an invalid TTL into the vhost would
     * fail nginx -t at apply time.
     */
    private function ttl(string $value, string $fallback): string
    {
        return preg_match('/^\d+(ms|s|m|h|d|w|M|y)?$/', trim($value)) === 1 ? trim($value) : $fallback;
    }

    /**
     * Bypass-and-no-cache variable list for fastcgi/proxy. Always includes
     * `$http_pragma` and `$http_authorization` (legacy default); appends
     * `$cookie_<name>` for each operator-listed cookie name.
     *
     * Cookie names are normalised to nginx's `$cookie_*` accessor grammar
     * (alphanumeric + underscore). Wildcarded names (`wordpress_logged_in_*`)
     * are dropped here — they require an http-level `map` block to expand
     * and that's a v2 follow-up.
     */
    private function nginxBypassVars(Site $site): string
    {
        $base = ['$http_pragma', '$http_authorization'];

        $cfg = $site->cachingConfig();
        $cookies = $cfg['nginx_http']['bypass_cookies'] ?? [];
        if (! is_array($cookies)) {
            return implode(' ', $base);
        }

        foreach ($cookies as $cookie) {
            if (! is_string($cookie) || $cookie === '' || str_contains($cookie, '*')) {
                continue;
            }
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $cookie);
            if (! is_string($safe) || $safe === '') {
                continue;
            }
            $base[] = '$cookie_'.$safe;
        }

        return implode(' ', $base);
    }
}
