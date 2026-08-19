<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Livewire\Sites\Caching;
use App\Models\ServerWebserverCacheFeature;
use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Live stats for the site Caching workspace: OPcache (FPM), nginx on-disk
 * FastCGI/proxy zones, and server-wide Varnish counters.
 *
 * Run deferred ({@see Caching::loadCacheStats}) — never
 * on the render path. HTTP-layer probes share one SSH round-trip.
 */
final class SiteCachingStatsReader
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
        protected SiteOpcacheManager $opcache,
    ) {}

    /**
     * @param  list<string>  $methods
     * @return array<string, mixed>
     */
    public function collect(Site $site, array $methods): array
    {
        $methods = array_values(array_unique($methods));
        $stats = [];

        if (in_array('opcache', $methods, true)) {
            $stats['opcache'] = $this->opcache->status($site);
        }

        $httpLayers = array_values(array_intersect($methods, ['nginx_http', 'varnish']));
        if ($httpLayers !== []) {
            $http = $this->probeHttpLayers($site, $httpLayers);
            if (in_array('nginx_http', $httpLayers, true)) {
                $stats['nginx_http'] = $http['nginx_http'] ?? null;
            }
            if (in_array('varnish', $httpLayers, true)) {
                $stats['varnish'] = $http['varnish'] ?? null;
            }
        }

        return $stats;
    }

    /**
     * @param  list<string>  $layers
     * @return array<string, mixed>
     */
    private function probeHttpLayers(Site $site, array $layers): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        $fcgiPath = (string) config('sites.nginx_engine_fcgi_cache_path');
        $proxyPath = (string) config('sites.nginx_engine_proxy_cache_path');
        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_NGINX,
        );
        $maxBytes = max(1, (int) $feature->nginx_zone_max_size_gb) * 1024 * 1024 * 1024;

        $wantNginx = in_array('nginx_http', $layers, true);
        $wantVarnish = in_array('varnish', $layers, true);

        $script = '';
        if ($wantNginx) {
            $script .= sprintf(
                <<<'BASH'
FCGI=%s
PROXY=%s
dir_stats() {
  prefix="$1"
  path="$2"
  if [ -d "$path" ]; then
    echo "${prefix}_present=1"
    echo "${prefix}_bytes=$(du -sb "$path" 2>/dev/null | awk '{print $1}')"
    echo "${prefix}_files=$(find "$path" -type f 2>/dev/null | wc -l | tr -d '[:space:]')"
  else
    echo "${prefix}_present=0"
    echo "${prefix}_bytes=0"
    echo "${prefix}_files=0"
  fi
}
dir_stats fcgi "$FCGI"
dir_stats proxy "$PROXY"
BASH,
                escapeshellarg($fcgiPath),
                escapeshellarg($proxyPath),
            );
        }

        if ($wantVarnish) {
            $script .= <<<'BASH'

if command -v varnishstat >/dev/null 2>&1; then
  echo 'VARNISH_JSON_BEGIN'
  varnishstat -j -1 2>/dev/null | head -c 200000
  echo
  echo 'VARNISH_JSON_END'
else
  echo 'varnish_missing=1'
fi
BASH;
        }

        try {
            $out = $this->remote->runInlineBash($server, 'site-caching-stats', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('sites.caching_stats_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return [];
        }

        $parsed = [];
        if ($wantNginx) {
            $parsed['nginx_http'] = [
                'ok' => true,
                'fcgi' => [
                    'present' => $this->parseInt($buffer, 'fcgi_present=') === 1,
                    'bytes' => $this->parseInt($buffer, 'fcgi_bytes='),
                    'files' => $this->parseInt($buffer, 'fcgi_files='),
                    'max_bytes' => $maxBytes,
                ],
                'proxy' => [
                    'present' => $this->parseInt($buffer, 'proxy_present=') === 1,
                    'bytes' => $this->parseInt($buffer, 'proxy_bytes='),
                    'files' => $this->parseInt($buffer, 'proxy_files='),
                    'max_bytes' => $maxBytes,
                ],
            ];
        }

        if ($wantVarnish) {
            $parsed['varnish'] = self::parseVarnishstatJson(self::extractBetween($buffer, 'VARNISH_JSON_BEGIN', 'VARNISH_JSON_END'));
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parseVarnishstatJson(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $hits = self::varnishCounter($decoded, 'MAIN.cache_hit');
        $hitsPass = self::varnishCounter($decoded, 'MAIN.cache_hitpass');
        $misses = self::varnishCounter($decoded, 'MAIN.cache_miss');
        $objects = self::varnishCounter($decoded, 'MAIN.n_object');
        $nuked = self::varnishCounter($decoded, 'MAIN.n_lru_nuked');
        $total = $hits + $misses;

        return [
            'ok' => true,
            'scope' => 'server',
            'hits' => $hits,
            'hits_pass' => $hitsPass,
            'misses' => $misses,
            'hit_rate' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'objects' => $objects,
            'nuked' => $nuked,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function varnishCounter(array $decoded, string $name): int
    {
        $counters = is_array($decoded['counters'] ?? null) ? $decoded['counters'] : $decoded;
        $row = $counters[$name] ?? null;
        if (is_array($row) && isset($row['value'])) {
            return (int) $row['value'];
        }

        return 0;
    }

    public static function extractBetween(string $buffer, string $start, string $end): string
    {
        $from = strpos($buffer, $start);
        $to = strpos($buffer, $end);
        if ($from === false || $to === false || $to <= $from) {
            return '';
        }

        return trim(substr($buffer, $from + strlen($start), $to - $from - strlen($start)));
    }

    private function parseInt(string $buffer, string $prefix): int
    {
        if (! preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $buffer, $m)) {
            return 0;
        }

        return (int) $m[1];
    }
}
