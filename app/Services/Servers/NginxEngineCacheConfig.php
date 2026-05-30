<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerWebserverCacheFeature;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Server-level FastCGI / proxy cache zones (RunCloud-style page caching).
 * Zone sizes persist in {@see ServerWebserverCacheFeature}; on save dply
 * rewrites the shared http{} include and reloads nginx.
 */
class NginxEngineCacheConfig
{
    /**
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'nginx_fcgi_zone_size_mb' => [
            'type' => 'int',
            'default' => '100',
            'label' => 'FastCGI keys_zone size (MB)',
            'help' => 'Shared memory for FastCGI cache keys. Referenced by site vhosts when engine HTTP cache is enabled.',
        ],
        'nginx_proxy_zone_size_mb' => [
            'type' => 'int',
            'default' => '100',
            'label' => 'Proxy keys_zone size (MB)',
            'help' => 'Shared memory for reverse-proxy cache keys.',
        ],
        'nginx_zone_max_size_gb' => [
            'type' => 'int',
            'default' => '2',
            'label' => 'On-disk max size (GB)',
            'help' => 'Upper bound for cached response bodies on disk per zone path.',
        ],
        'nginx_zone_inactive_minutes' => [
            'type' => 'int',
            'default' => '60',
            'label' => 'Inactive purge (minutes)',
            'help' => 'Remove cache entries not accessed within this window.',
        ],
    ];

    /**
     * @return array{
     *     values: array<string, string>,
     *     fcgi_path: string,
     *     proxy_path: string,
     *     fcgi_zone: string,
     *     proxy_zone: string,
     *     conf_path: string,
     * }
     */
    public function read(Server $server): array
    {
        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_NGINX,
        );

        $values = [];
        foreach (self::PARAMS as $key => $meta) {
            $values[$key] = (string) ($feature->{$key} ?? $meta['default']);
        }

        return [
            'values' => $values,
            'fcgi_path' => (string) config('sites.nginx_engine_fcgi_cache_path'),
            'proxy_path' => (string) config('sites.nginx_engine_proxy_cache_path'),
            'fcgi_zone' => (string) config('sites.nginx_engine_fcgi_cache_zone'),
            'proxy_zone' => (string) config('sites.nginx_engine_proxy_cache_zone'),
            'conf_path' => (string) config('sites.nginx_engine_http_cache_conf'),
        ];
    }

    public function renderConfContents(ServerWebserverCacheFeature $feature): string
    {
        $fcgiPath = (string) config('sites.nginx_engine_fcgi_cache_path');
        $proxyPath = (string) config('sites.nginx_engine_proxy_cache_path');
        $fcgiZone = (string) config('sites.nginx_engine_fcgi_cache_zone');
        $proxyZone = (string) config('sites.nginx_engine_proxy_cache_zone');

        $fcgiSize = max(1, (int) $feature->nginx_fcgi_zone_size_mb);
        $proxySize = max(1, (int) $feature->nginx_proxy_zone_size_mb);
        $maxSize = max(1, (int) $feature->nginx_zone_max_size_gb);
        $inactive = max(1, (int) $feature->nginx_zone_inactive_minutes);

        $contents = "# Managed by Dply — shared HTTP cache zones (do not edit by hand)\n";
        $contents .= "fastcgi_cache_path {$fcgiPath} levels=1:2 keys_zone={$fcgiZone}:{$fcgiSize}m inactive={$inactive}m max_size={$maxSize}g;\n";
        $contents .= "proxy_cache_path {$proxyPath} levels=1:2 keys_zone={$proxyZone}:{$proxySize}m inactive={$inactive}m max_size={$maxSize}g;\n";

        return $contents;
    }

    /**
     * @param  array<string, string|int>  $values
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        $feature = ServerWebserverCacheFeature::findOrCreateFor(
            $server->id,
            ServerWebserverCacheFeature::WEBSERVER_NGINX,
        );

        foreach (self::PARAMS as $key => $meta) {
            $raw = $values[$key] ?? $meta['default'];
            $feature->{$key} = max(1, (int) $raw);
        }
        $feature->save();

        $contents = $this->renderConfContents($feature);
        $confPath = (string) config('sites.nginx_engine_http_cache_conf');
        $fcgiPath = (string) config('sites.nginx_engine_fcgi_cache_path');
        $proxyPath = (string) config('sites.nginx_engine_proxy_cache_path');

        $ssh = new SshConnection($server);

        $emit->step('nginx-cache', 'Staging engine cache config');
        $tmpRemote = '/tmp/dply-nginx-engine-cache.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($contents);
        $writeCmd = sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null',
            escapeshellarg($encoded),
            escapeshellarg($tmpRemote),
        );
        $ssh->exec($writeCmd, 15);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to stage nginx engine cache config on the server.');
        }

        $bak = $confPath.'.dply-bak.'.now()->format('YmdHis');
        $ssh->exec(sprintf('sudo -n test -f %s && sudo -n cp -p %s %s || true', escapeshellarg($confPath), escapeshellarg($confPath), escapeshellarg($bak)), 10);

        $emit->step('nginx-cache', 'Installing '.$confPath);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg($confPath)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            throw new \RuntimeException('Failed to install nginx engine cache config.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('nginx-cache', 'Preparing cache directories');
        $mkdir = sprintf(
            'mkdir -p %s %s && (chown -R www-data:www-data %s %s 2>/dev/null || chown -R nginx:nginx %s %s 2>/dev/null || true)',
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
            escapeshellarg($fcgiPath),
            escapeshellarg($proxyPath),
        );
        $ssh->exec('sudo -n bash -lc '.escapeshellarg($mkdir), 30);

        $emit->step('nginx-cache', 'Validating with `nginx -t`');
        $validate = $ssh->exec('sudo -n nginx -t 2>&1; echo "__exit__:$?"', 30);
        $exit = $this->parseExitMarker($validate);
        $validateOutput = trim($this->stripExitMarker($validate));
        if ($exit !== 0) {
            if ($bak !== '') {
                $ssh->exec(sprintf('sudo -n test -f %s && sudo -n cp -p %s %s || true', escapeshellarg($bak), escapeshellarg($bak), escapeshellarg($confPath)), 10);
            }
            throw new \RuntimeException('nginx -t failed; previous cache config restored if a backup existed.'."\n".$validateOutput);
        }

        $emit->step('nginx-cache', 'Reloading nginx');
        $reload = $ssh->exec('sudo -n systemctl reload nginx 2>&1; echo "__exit__:$?"', 20);
        if ($this->parseExitMarker($reload) !== 0) {
            $ssh->exec('sudo -n systemctl restart nginx 2>&1', 30);
        }

        $emit->success('nginx engine cache config saved.');
    }

    private function parseExitMarker(string $output): int
    {
        if (preg_match('/__exit__:(\d+)\s*$/', $output, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function stripExitMarker(string $output): string
    {
        return (string) preg_replace('/\n?__exit__:\d+\s*$/', '', $output);
    }
}
