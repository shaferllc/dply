<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Renders the monolithic `/etc/openresty/nginx.conf` for a dply-edged
 * OpenResty server. One HTTP listener on $listenPort, one `server {}` per
 * site keyed by Host header, upstream pools pointing at per-site Caddy
 * backends on ephemeral high ports, plus a catch-all 503 server block.
 */
class OpenRestyEdgeConfigBuilder
{
    /**
     * @param  Collection<int, Site>  $sites
     * @param  callable(Site): int  $backendPortFor
     * @param  list<array{name: string, servers?: list<string>}>  $customUpstreams
     * @param  list<array{name: string, server_names?: list<string>, upstream?: string}>  $customServers
     * @param  array{worker_processes?: string, worker_connections?: string|int, client_max_body_size?: string, proxy_read_timeout?: string, status_port?: string|int}  $operatorSettings
     */
    public function build(
        Collection $sites,
        int $listenPort,
        callable $backendPortFor,
        array $customUpstreams = [],
        array $customServers = [],
        array $operatorSettings = [],
    ): string {
        $settings = array_merge(
            OpenRestyStaticConfigOptions::defaultOperatorSettings(),
            $operatorSettings,
        );
        $workerProcesses = trim((string) ($settings['worker_processes'] ?? 'auto')) ?: 'auto';
        $workerConnections = max(128, (int) ($settings['worker_connections'] ?? 1024));
        $clientMaxBodySize = trim((string) ($settings['client_max_body_size'] ?? '64m')) ?: '64m';
        $proxyReadTimeout = trim((string) ($settings['proxy_read_timeout'] ?? '60s')) ?: '60s';
        $statusPort = max(1024, min(65535, (int) ($settings['status_port'] ?? 9149)));

        $upstreamBlocks = [];
        $serverBlocks = [];

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $upstreamName = 'bk_'.$this->sanitize($basename);
            $port = (int) $backendPortFor($site);
            $hostnames = $this->hostnamesFor($site);
            if ($hostnames === []) {
                continue;
            }

            $upstreamBlocks[] = $this->renderUpstream($upstreamName, ['127.0.0.1:'.$port]);
            $serverBlocks[] = $this->renderServerBlock(
                'srv_'.$this->sanitize($basename),
                $hostnames,
                $listenPort,
                $upstreamName,
            );
        }

        foreach ($customUpstreams as $custom) {
            if (! is_array($custom)) {
                continue;
            }
            $name = trim((string) ($custom['name'] ?? ''));
            $servers = array_values(array_filter(
                array_map('trim', (array) ($custom['servers'] ?? [])),
                fn (string $s): bool => $s !== '',
            ));
            if ($name === '' || $servers === []) {
                continue;
            }
            $upstreamBlocks[] = $this->renderUpstream($name, $servers);
        }

        foreach ($customServers as $custom) {
            if (! is_array($custom)) {
                continue;
            }
            $name = trim((string) ($custom['name'] ?? ''));
            $upstream = trim((string) ($custom['upstream'] ?? ''));
            $serverNames = array_values(array_filter(
                array_map('trim', (array) ($custom['server_names'] ?? [])),
                fn (string $d): bool => $d !== '' && $d !== '_',
            ));
            if ($name === '' || $upstream === '' || $serverNames === []) {
                continue;
            }
            $serverBlocks[] = $this->renderServerBlock(
                'srv_custom_'.$this->sanitize($name),
                $serverNames,
                $listenPort,
                $upstream,
            );
        }

        $serverBlocks[] = $this->renderUnmatchedServer($listenPort);
        $serverBlocks[] = $this->renderStatusServer($statusPort);

        $upstreamSection = $upstreamBlocks !== [] ? implode("\n\n", $upstreamBlocks)."\n\n" : '';
        $serverSection = implode("\n\n", $serverBlocks);

        return <<<NGINX
# Managed by Dply — do NOT hand-edit. Regenerated on every edge routing rebuild.
worker_processes {$workerProcesses};
error_log /var/log/openresty/error.log warn;
pid /usr/local/openresty/nginx/logs/nginx.pid;

events {
    worker_connections {$workerConnections};
}

http {
    include       /etc/openresty/mime.types;
    default_type  application/octet-stream;
    access_log    /var/log/openresty/access.log;
    sendfile      on;
    keepalive_timeout 65;
    client_max_body_size {$clientMaxBodySize};
    proxy_read_timeout {$proxyReadTimeout};
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;

{$upstreamSection}{$serverSection}
}
NGINX;
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function buildForServer(Server $server, Collection $sites, int $listenPort, callable $backendPortFor): string
    {
        return $this->build(
            $sites,
            $listenPort,
            $backendPortFor,
            OpenRestyCustomUpstreamsConfig::upstreamsFromServer($server),
            OpenRestyCustomServersConfig::serversFromServer($server),
            OpenRestyStaticConfigOptions::operatorSettingsFromServer($server),
        );
    }

    /**
     * @param  array<string, mixed> $servers
     */
    private function renderUpstream(string $name, array $servers): string
    {
        $lines = [];
        foreach ($servers as $server) {
            [$host, $port] = $this->splitHostPort($server);
            $lines[] = "        server {$host}:{$port};";
        }

        $serverLines = implode("\n", $lines);

        return <<<NGINX
    upstream {$name} {
{$serverLines}
    }
NGINX;
    }

    /**
     * @param  array<string, mixed> $serverNames
     */
    private function renderServerBlock(string $blockName, array $serverNames, int $listenPort, string $upstreamName): string
    {
        $names = $this->serverNamesForPort($serverNames, $listenPort);
        $nameLine = implode(' ', $names);

        return <<<NGINX
    # {$blockName}
    server {
        listen {$listenPort};
        server_name {$nameLine};
        location / {
            proxy_pass http://{$upstreamName};
        }
    }
NGINX;
    }

    private function renderUnmatchedServer(int $listenPort): string
    {
        return <<<NGINX
    server {
        listen {$listenPort} default_server;
        server_name _;
        default_type text/plain;
        return 503 "dply: no backend matches this host\n";
    }
NGINX;
    }

    private function renderStatusServer(int $statusPort): string
    {
        return <<<NGINX
    server {
        listen 127.0.0.1:{$statusPort};
        server_name localhost;
        location /nginx_status {
            stub_status on;
            access_log off;
            allow 127.0.0.1;
            deny all;
        }
    }
NGINX;
    }

    /**
     * @param  array<string, mixed> $serverNames
     * @return list<string>
     */
    private function serverNamesForPort(array $serverNames, int $listenPort): array
    {
        $out = [];
        foreach ($serverNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $out[] = $name;
            $out[] = $name.':'.$listenPort;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $endpoint): array
    {
        if (preg_match('/^(.+):(\d+)$/', trim($endpoint), $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return ['127.0.0.1', 8080];
    }

    /**
     * @return list<string>
     */
    private function hostnamesFor(Site $site): array
    {
        return collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function basenameFor(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
    }

    private function sanitize(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?? 'site';
    }
}
