<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes Traefik's live state via the localhost-only API at :9094
 * (api.insecure on the `traefik` entry-point — set up by
 * {@see AddEdgeProxyJob::writeTraefikStaticConfig()}).
 *
 * dply runs `curl` on the box to hit /api/http/{routers,services,middlewares}
 * and /api/{overview,version}. JSON parsed in PHP and normalized into the
 * four sub-tab unit arrays (routers/services/middlewares/providers).
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
        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript();
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
                'providers' => $this->buildProviderUnits($routers, $services),
            ],
            engineSpecific: array_filter([
                'overview' => is_array($overview) ? $overview : null,
                'version' => is_array($version) ? $version : null,
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * One bash heredoc that curls all the endpoints we need with section
     * markers between them. Lower latency than 5 separate SSH calls.
     */
    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
URL="http://127.0.0.1:9094"
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
        $heads = ['routers', 'services', 'middlewares', 'overview', 'version'];
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
     * Providers don't have a dedicated /api/providers/<name> endpoint that
     * returns a clean list. We derive from routers + services: each one
     * carries a `provider` field; group counts per provider.
     *
     * @param  array<int, array<string, mixed>>  $routers
     * @param  array<int, array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    private function buildProviderUnits(array $routers, array $services): array
    {
        $counts = [];
        foreach ($routers as $r) {
            $p = is_array($r) ? (string) ($r['provider'] ?? '') : '';
            if ($p === '') {
                continue;
            }
            $counts[$p]['routers'] = ($counts[$p]['routers'] ?? 0) + 1;
        }
        foreach ($services as $s) {
            $p = is_array($s) ? (string) ($s['provider'] ?? '') : '';
            if ($p === '') {
                continue;
            }
            $counts[$p]['services'] = ($counts[$p]['services'] ?? 0) + 1;
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
