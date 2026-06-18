<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\Servers\TraefikAdminApiResolver;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes Traefik's live state via the localhost-only API at :9094
 * (api.insecure on the `traefik` entry-point — set up by
 * {@see AddEdgeProxyJob::writeTraefikStaticConfig()}).
 *
 * dply runs `curl` on the box against the Traefik REST API (see
 * https://doc.traefik.io/traefik/operations/api/) — HTTP/TCP routers &
 * services, middlewares, entrypoints, TLS stores, overview, and version.
 * JSON is parsed in PHP and normalized into workspace sub-tab unit arrays.
 */
class TraefikLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'traefik';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        if ($standby = $this->inactiveEdgeProxyLiveState($server)) {
            return $standby;
        }

        try {
            $adminBase = rtrim(app(TraefikAdminApiResolver::class)->resolve($server)['base_url'], '/');
        } catch (\Throwable $e) {
            return new EngineLiveState(
                engine: $this->engineKey(),
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: [],
                engineSpecific: [
                    'errors' => [$e->getMessage()],
                ],
            );
        }

        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript($adminBase);
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('Traefik API SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $routers = $this->decodeJson($sections['routers'] ?? '');
        $services = $this->decodeJson($sections['services'] ?? '');
        $middlewares = $this->decodeJson($sections['middlewares'] ?? '');
        $entrypoints = $this->decodeJson($sections['entrypoints'] ?? '');
        $tcpRouters = $this->decodeJson($sections['tcp_routers'] ?? '');
        $tcpServices = $this->decodeJson($sections['tcp_services'] ?? '');
        $udpRouters = $this->decodeJson($sections['udp_routers'] ?? '');
        $udpServices = $this->decodeJson($sections['udp_services'] ?? '');
        $tlsStores = $this->decodeJson($sections['tls_stores'] ?? '');
        $overview = $this->decodeJson($sections['overview'] ?? '');
        $version = $this->decodeJson($sections['version'] ?? '');

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'routers' => $this->buildRouterUnits($routers),
                'services' => $this->buildServiceUnits($services),
                'middlewares' => $this->buildMiddlewareUnits($middlewares),
                'entrypoints' => $this->buildEntrypointUnits($entrypoints),
                'tcprouters' => $this->buildTcpRouterUnits($tcpRouters),
                'tcpservices' => $this->buildTcpServiceUnits($tcpServices),
                'udprouters' => $this->buildUdpRouterUnits($udpRouters),
                'udpservices' => $this->buildUdpServiceUnits($udpServices),
                'tls' => $this->buildTlsUnits($tlsStores),
                'providers' => $this->buildProviderUnits($routers, $services, $tcpRouters, $tcpServices),
            ],
            engineSpecific: array_filter([
                'overview' => ($overview ),
                'version' => ($version ),
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * One bash heredoc that curls all the endpoints we need with section
     * markers between them. Lower latency than 5 separate SSH calls.
     */
    private function buildProbeScript(string $adminBase): string
    {
        $url = escapeshellarg($adminBase);

        return <<<BASH
set +e
URL={$url}
echo '###dply-section:routers###'
curl -fsS --max-time 5 "$URL/api/http/routers" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:services###'
curl -fsS --max-time 5 "$URL/api/http/services" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:middlewares###'
curl -fsS --max-time 5 "$URL/api/http/middlewares" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:entrypoints###'
curl -fsS --max-time 5 "$URL/api/entrypoints" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:tcp_routers###'
curl -fsS --max-time 5 "$URL/api/tcp/routers" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:tcp_services###'
curl -fsS --max-time 5 "$URL/api/tcp/services" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:udp_routers###'
curl -fsS --max-time 5 "$URL/api/udp/routers" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:udp_services###'
curl -fsS --max-time 5 "$URL/api/udp/services" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:tls_stores###'
curl -fsS --max-time 5 "$URL/api/tls/stores" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:overview###'
curl -fsS --max-time 5 "$URL/api/overview" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:version###'
curl -fsS --max-time 5 "$URL/api/version" 2>/dev/null
echo
echo '###dply-section:end###'
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = [
            'routers', 'services', 'middlewares', 'entrypoints',
            'tcp_routers', 'tcp_services', 'udp_routers', 'udp_services', 'tls_stores',
            'overview', 'version',
        ];
        $end = '###dply-section:end###';
        $out = [];
        foreach ($heads as $name) {
            $head = '###dply-section:'.$name.'###';
            $start = strpos($output, $head);
            if ($start === false) {
                continue;
            }
            $start += strlen($head);
            $stop = strpos($output, $end, $start);
            $out[$name] = $stop === false ? substr($output, $start) : substr($output, $start, $stop - $start);
        }

        return $out;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJson(string $blob): array
    {
        $blob = trim($blob);
        if ($blob === '') {
            return [];
        }
        $decoded = json_decode($blob, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Traefik /api/http/routers returns a list of router objects:
     *   { name, rule, entryPoints, service, middlewares, status, provider, priority }
     *
     * @param  array<int, array<string, mixed>>  $routers
     * @return list<array<string, mixed>>
     */
    private function buildRouterUnits(array $routers): array
    {
        if ($routers === [] || ! isset($routers[0])) {
            return [];
        }
        $rows = [];
        foreach ($routers as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rows[] = [
                'name' => (string) ($r['name'] ?? '?'),
                'rule' => (string) ($r['rule'] ?? ''),
                'service' => (string) ($r['service'] ?? ''),
                'middlewares' => is_array($r['middlewares'] ?? null) ? array_values($r['middlewares']) : [],
                'entry_points' => is_array($r['entryPoints'] ?? null) ? array_values($r['entryPoints']) : [],
                'status' => (string) ($r['status'] ?? 'unknown'),
                'provider' => (string) ($r['provider'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * /api/http/services returns service objects with a `loadBalancer.servers`
     * list and a `serverStatus` map of url → "UP"/"DOWN".
     *
     * @param  array<int, array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private function buildServiceUnits(array $services): array
    {
        if ($services === [] || ! isset($services[0])) {
            return [];
        }
        $rows = [];
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            $loadBalancer = is_array($s['loadBalancer'] ?? null) ? $s['loadBalancer'] : [];
            $servers = is_array($loadBalancer['servers'] ?? null) ? $loadBalancer['servers'] : [];
            $serverUrls = array_values(array_map(
                static fn ($srv): string => is_array($srv) ? (string) ($srv['url'] ?? '?') : '?',
                $servers,
            ));
            $serverStatus = is_array($s['serverStatus'] ?? null) ? $s['serverStatus'] : [];

            $rows[] = [
                'name' => (string) ($s['name'] ?? '?'),
                'type' => (string) ($s['type'] ?? 'loadBalancer'),
                'servers' => $serverUrls,
                'server_status' => $serverStatus,
                'provider' => (string) ($s['provider'] ?? ''),
                'status' => (string) ($s['status'] ?? 'unknown'),
            ];
        }

        return $rows;
    }

    /**
     * /api/http/middlewares returns middleware objects with a `type` field
     * (basicAuth/forwardAuth/headers/rateLimit/etc.) and a config blob
     * specific to the middleware type. We surface name/type/provider for
     * the table — operators drill into the config via the dply config
     * editor for the relevant dynamic YAML.
     *
     * @param  array<int, array<string, mixed>>  $middlewares
     * @return list<array<string, mixed>>
     */
    private function buildMiddlewareUnits(array $middlewares): array
    {
        if ($middlewares === [] || ! isset($middlewares[0])) {
            return [];
        }
        $rows = [];
        foreach ($middlewares as $m) {
            if (! is_array($m)) {
                continue;
            }
            $rows[] = [
                'name' => (string) ($m['name'] ?? '?'),
                'type' => (string) ($m['type'] ?? '?'),
                'provider' => (string) ($m['provider'] ?? ''),
                'status' => (string) ($m['status'] ?? 'unknown'),
            ];
        }

        return $rows;
    }

    /**
     * /api/entrypoints — listen addresses and transport for each named entry point.
     *
     * @param  array<int, array<string, mixed>>  $entrypoints
     * @return list<array<string, mixed>>
     */
    private function buildEntrypointUnits(array $entrypoints): array
    {
        if ($entrypoints === []) {
            return [];
        }

        // Traefik returns a map keyed by entry-point name, not a JSON array.
        $items = isset($entrypoints[0]) ? $entrypoints : array_map(
            static fn (string $name, mixed $ep): array => is_array($ep) ? array_merge($ep, ['name' => $name]) : ['name' => $name],
            array_keys($entrypoints),
            array_values($entrypoints),
        );

        $rows = [];
        foreach ($items as $ep) {
            if (! is_array($ep)) {
                continue;
            }
            $address = $ep['address'] ?? null;
            if ($address === null && is_array($ep['http'] ?? null)) {
                $address = $ep['http']['address'] ?? null;
            }
            $rows[] = [
                'name' => (string) ($ep['name'] ?? '?'),
                'address' => is_string($address) ? $address : (is_array($address) ? implode(', ', $address) : '—'),
                'transport' => (string) ($ep['transport'] ?? (isset($ep['http']) ? 'http' : (isset($ep['tcp']) ? 'tcp' : (isset($ep['udp']) ? 'udp' : '')))),
                'status' => (string) ($ep['status'] ?? 'unknown'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $routers
     * @return list<array<string, mixed>>
     */
    private function buildTcpRouterUnits(array $routers): array
    {
        if ($routers === [] || ! isset($routers[0])) {
            return [];
        }
        $rows = [];
        foreach ($routers as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rows[] = [
                'name' => (string) ($r['name'] ?? '?'),
                'rule' => (string) ($r['rule'] ?? ''),
                'service' => (string) ($r['service'] ?? ''),
                'entry_points' => is_array($r['entryPoints'] ?? null) ? array_values($r['entryPoints']) : [],
                'status' => (string) ($r['status'] ?? 'unknown'),
                'provider' => (string) ($r['provider'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private function buildTcpServiceUnits(array $services): array
    {
        if ($services === [] || ! isset($services[0])) {
            return [];
        }
        $rows = [];
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            $loadBalancer = is_array($s['loadBalancer'] ?? null) ? $s['loadBalancer'] : [];
            $servers = is_array($loadBalancer['servers'] ?? null) ? $loadBalancer['servers'] : [];
            $addrs = array_values(array_map(
                static fn ($srv): string => is_array($srv) ? (string) ($srv['address'] ?? '?') : '?',
                $servers,
            ));

            $rows[] = [
                'name' => (string) ($s['name'] ?? '?'),
                'servers' => $addrs,
                'provider' => (string) ($s['provider'] ?? ''),
                'status' => (string) ($s['status'] ?? 'unknown'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $routers
     * @return list<array<string, mixed>>
     */
    private function buildUdpRouterUnits(array $routers): array
    {
        if ($routers === [] || ! isset($routers[0])) {
            return [];
        }
        $rows = [];
        foreach ($routers as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rows[] = [
                'name' => (string) ($r['name'] ?? '?'),
                'service' => (string) ($r['service'] ?? ''),
                'entry_points' => is_array($r['entryPoints'] ?? null) ? array_values($r['entryPoints']) : [],
                'status' => (string) ($r['status'] ?? 'unknown'),
                'provider' => (string) ($r['provider'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private function buildUdpServiceUnits(array $services): array
    {
        if ($services === [] || ! isset($services[0])) {
            return [];
        }
        $rows = [];
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            $loadBalancer = is_array($s['loadBalancer'] ?? null) ? $s['loadBalancer'] : [];
            $servers = is_array($loadBalancer['servers'] ?? null) ? $loadBalancer['servers'] : [];
            $addrs = array_values(array_map(
                static fn ($srv): string => is_array($srv) ? (string) ($srv['address'] ?? '?') : '?',
                $servers,
            ));

            $rows[] = [
                'name' => (string) ($s['name'] ?? '?'),
                'servers' => $addrs,
                'provider' => (string) ($s['provider'] ?? ''),
                'status' => (string) ($s['status'] ?? 'unknown'),
            ];
        }

        return $rows;
    }

    /**
     * /api/tls/stores — default certificate stores and stored certs.
     *
     * @param  array<string, mixed> $stores
     * @return list<array<string, mixed>>
     */
    private function buildTlsUnits(array $stores): array
    {
        if ($stores === []) {
            return [];
        }

        // API returns a map keyed by store name (e.g. "default").
        $items = isset($stores[0]) ? $stores : array_map(
            static fn (string $name, mixed $store): array => is_array($store) ? array_merge($store, ['name' => $name]) : ['name' => $name],
            array_keys($stores),
            array_values($stores),
        );
        $rows = [];
        foreach ($items as $store) {
            if (! is_array($store)) {
                continue;
            }
            $name = (string) ($store['name'] ?? 'default');
            $certs = is_array($store['certificates'] ?? null)
                ? $store['certificates']
                : (is_array($store['defaultCertificate'] ?? null) ? [$store['defaultCertificate']] : []);
            if ($certs === []) {
                $rows[] = [
                    'store' => $name,
                    'subject' => '—',
                    'sans' => [],
                    'status' => (string) ($store['status'] ?? '—'),
                ];

                continue;
            }
            foreach ($certs as $cert) {
                if (! is_array($cert)) {
                    continue;
                }
                $sans = is_array($cert['sans'] ?? null) ? array_values($cert['sans']) : [];
                $rows[] = [
                    'store' => $name,
                    'subject' => (string) ($cert['subject'] ?? ($sans[0] ?? '—')),
                    'sans' => $sans,
                    'status' => (string) ($cert['status'] ?? ($store['status'] ?? '—')),
                ];
            }
        }

        return $rows;
    }

    /**
     * Providers don't have a dedicated /api/providers/<name> endpoint that
     * returns a clean list. We derive from routers + services: each one
     * carries a `provider` field; group counts per provider.
     *
     * @param  array<int, array<string, mixed>>  $routers
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $tcpRouters
     * @param  array<int, array<string, mixed>>  $tcpServices
     * @return list<array<string, mixed>>
     */
    private function buildProviderUnits(array $routers, array $services, array $tcpRouters = [], array $tcpServices = []): array
    {
        $counts = [];
        foreach ([$routers, $tcpRouters] as $routerList) {
            foreach ($routerList as $r) {
                $p = is_array($r) ? (string) ($r['provider'] ?? '') : '';
                if ($p === '') {
                    continue;
                }
                $counts[$p]['routers'] = ($counts[$p]['routers'] ?? 0) + 1;
            }
        }
        foreach ([$services, $tcpServices] as $serviceList) {
            foreach ($serviceList as $s) {
                $p = is_array($s) ? (string) ($s['provider'] ?? '') : '';
                if ($p === '') {
                    continue;
                }
                $counts[$p]['services'] = ($counts[$p]['services'] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($counts as $provider => $sub) {
            $rows[] = [
                'name' => $provider,
                'router_count' => (int) ($sub['routers'] ?? 0),
                'service_count' => (int) ($sub['services'] ?? 0),
            ];
        }

        return $rows;
    }
}
