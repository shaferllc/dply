<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses dply's monolithic {@see /etc/envoy/envoy.yaml} for UI surfaces
 * (virtual hosts tab, custom cluster editor, static tunables).
 */
final class EnvoyConfigParser
{
    public const DPLY_MANAGED_HEADER = '# Managed by Dply';

    /**
     * @return list<array{name: string, domains: list<string>, cluster: string, dply_managed: bool, site_id: ?string}>
     */
    public static function virtualHosts(string $yaml): array
    {
        $data = self::parse($yaml);
        if ($data === []) {
            return [];
        }

        $hosts = [];
        foreach (self::listenerFilterChains($data) as $chain) {
            $routeConfig = self::httpConnectionManagerRouteConfig($chain);
            if ($routeConfig === null) {
                continue;
            }
            foreach ($routeConfig['virtual_hosts'] ?? [] as $vh) {
                if (! is_array($vh)) {
                    continue;
                }
                $name = trim((string) ($vh['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $domains = self::stringList($vh['domains'] ?? []);
                $cluster = self::firstRouteCluster($vh['routes'] ?? []);
                $siteId = self::siteIdFromVhostName($name);
                $hosts[] = [
                    'name' => $name,
                    'domains' => $domains,
                    'cluster' => $cluster,
                    'dply_managed' => $siteId !== null || $name === 'dply_unmatched',
                    'site_id' => $siteId,
                ];
            }
        }

        return $hosts;
    }

    /**
     * @return list<array{name: string, connect_timeout: string, lb_policy: string, endpoints: list<string>, dply_managed: bool}>
     */
    public static function clusters(string $yaml): array
    {
        $data = self::parse($yaml);
        if ($data === []) {
            return [];
        }

        $vhostClusters = [];
        foreach (self::virtualHosts($yaml) as $vh) {
            if ($vh['cluster'] !== '') {
                $vhostClusters[$vh['cluster']] = true;
            }
        }

        $out = [];
        foreach ($data['static_resources']['clusters'] ?? [] as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $name = trim((string) ($cluster['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $endpoints = [];
            foreach ($cluster['load_assignment']['endpoints'] ?? [] as $ep) {
                if (! is_array($ep)) {
                    continue;
                }
                foreach ($ep['lb_endpoints'] ?? [] as $lb) {
                    if (! is_array($lb)) {
                        continue;
                    }
                    $addr = $lb['endpoint']['address']['socket_address'] ?? null;
                    if (! is_array($addr)) {
                        continue;
                    }
                    $host = (string) ($addr['address'] ?? '127.0.0.1');
                    $port = (int) ($addr['port_value'] ?? 0);
                    if ($port > 0) {
                        $endpoints[] = $host.':'.$port;
                    }
                }
            }
            $dplyManaged = isset($vhostClusters[$name]) && str_starts_with($name, 'cluster_');
            $out[] = [
                'name' => $name,
                'connect_timeout' => (string) ($cluster['connect_timeout'] ?? '5s'),
                'lb_policy' => (string) ($cluster['lb_policy'] ?? 'ROUND_ROBIN'),
                'endpoints' => $endpoints,
                'dply_managed' => $dplyManaged,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, connect_timeout: string, lb_policy: string, endpoints: list<string>}>
     */
    public static function customClusters(string $yaml): array
    {
        return array_values(array_filter(
            self::clusters($yaml),
            fn (array $c): bool => ! ($c['dply_managed']),
        ));
    }

    /**
     * @param  list<array{name: string, connect_timeout: string, lb_policy: string, endpoints: list<string>}>  $clusters
     */
    public static function renderClusterBlock(array $clusters): string
    {
        if ($clusters === []) {
            return '';
        }

        $lines = [];
        foreach ($clusters as $cluster) {
            $name = trim($cluster['name']);
            if ($name === '') {
                continue;
            }
            $timeout = trim($cluster['connect_timeout']) ?: '5s';
            $policy = trim($cluster['lb_policy']) ?: 'ROUND_ROBIN';
            $endpoints = array_values(array_filter(
                array_map('trim', $cluster['endpoints']),
                fn (string $e): bool => $e !== '',
            ));
            if ($endpoints === []) {
                continue;
            }

            $lines[] = '  - name: '.$name;
            $lines[] = '    connect_timeout: '.$timeout;
            $lines[] = '    type: STATIC';
            $lines[] = '    lb_policy: '.$policy;
            $lines[] = '    load_assignment:';
            $lines[] = '      cluster_name: '.$name;
            $lines[] = '      endpoints:';
            $lines[] = '      - lb_endpoints:';
            foreach ($endpoints as $endpoint) {
                [$host, $port] = self::splitHostPort($endpoint);
                $lines[] = '        - endpoint:';
                $lines[] = '            address:';
                $lines[] = '              socket_address:';
                $lines[] = '                address: '.$host;
                $lines[] = '                port_value: '.$port;
            }
        }

        return implode("\n", $lines).($lines !== [] ? "\n" : '');
    }

    /**
     * @return array<string, mixed>
     */
    public static function operatorSettings(string $yaml): array
    {
        $data = self::parse($yaml);
        if ($data === []) {
            return self::defaultOperatorSettings();
        }

        $adminPort = (int) data_get($data, 'admin.address.socket_address.port_value', 9901);
        $statPrefix = (string) data_get(
            $data,
            'static_resources.listeners.0.filter_chains.0.filters.0.typed_config.stat_prefix',
            'dply_ingress',
        );

        return [
            'admin_port' => $adminPort > 0 ? (string) $adminPort : '9901',
            'stat_prefix' => $statPrefix !== '' ? $statPrefix : 'dply_ingress',
        ];
    }

    /**
     * @return array{admin_port: string, stat_prefix: string}
     */
    public static function defaultOperatorSettings(): array
    {
        return [
            'admin_port' => '9901',
            'stat_prefix' => 'dply_ingress',
        ];
    }

    /**
     * @param  array<string, mixed> $settings
     */
    public static function mergeOperatorSettings(string $yaml, array $settings): string
    {
        $data = self::parse($yaml);
        if ($data === []) {
            return $yaml;
        }

        $port = max(1, min(65535, (int) ($settings['admin_port'] ?? 9901)));
        data_set($data, 'admin.address.socket_address.address', '127.0.0.1');
        data_set($data, 'admin.address.socket_address.port_value', $port);

        $prefix = trim((string) ($settings['stat_prefix'] ?? 'dply_ingress'));
        if ($prefix !== '') {
            data_set(
                $data,
                'static_resources.listeners.0.filter_chains.0.filters.0.typed_config.stat_prefix',
                $prefix,
            );
        }

        return Yaml::dump($data, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $yaml): array
    {
        $trimmed = trim($yaml);
        if ($trimmed === '') {
            return [];
        }

        try {
            $parsed = Yaml::parse($trimmed);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function listenerFilterChains(array $data): array
    {
        $chains = [];
        foreach ($data['static_resources']['listeners'] ?? [] as $listener) {
            if (! is_array($listener)) {
                continue;
            }
            foreach ($listener['filter_chains'] ?? [] as $chain) {
                if (is_array($chain)) {
                    $chains[] = $chain;
                }
            }
        }

        return $chains;
    }

    /**
     * @param  array<string, mixed> $chain
     * @return array<string, mixed>|null
     */
    private static function httpConnectionManagerRouteConfig(array $chain): ?array
    {
        foreach ($chain['filters'] ?? [] as $filter) {
            if (! is_array($filter)) {
                continue;
            }
            if (($filter['name'] ?? '') !== 'envoy.filters.network.http_connection_manager') {
                continue;
            }
            $route = data_get($filter, 'typed_config.route_config');

            return is_array($route) ? $route : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v): string => trim((string) $v),
            $values,
        ), fn (string $s): bool => $s !== '' && $s !== '*'));
    }

    private static function firstRouteCluster(mixed $routes): string
    {
        if (! is_array($routes)) {
            return '';
        }
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $cluster = trim((string) data_get($route, 'route.cluster', ''));

            return $cluster;
        }

        return '';
    }

    private static function siteIdFromVhostName(string $name): ?string
    {
        if (preg_match('/^vhost_dply-([0-9a-z]+)-/i', $name, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function splitHostPort(string $endpoint): array
    {
        if (preg_match('/^(.+):(\d+)$/', trim($endpoint), $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return ['127.0.0.1', 8080];
    }
}
