<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * Probes Caddy's live state via its localhost admin API (default
 * 127.0.0.1:2019). Caddy enables the admin endpoint automatically unless
 * the operator set `admin off` in the global Caddyfile block — when
 * unreachable we surface an error in engineSpecific and the UI falls
 * back to an empty state.
 *
 * We hit:
 *   - GET /config/                       — full active config (JSON)
 *   - GET /reverse_proxy/upstreams       — backends + health counters
 *   - GET /pki/ca/local                  — internal CA info (Caddy auto-issued)
 *
 * Parsed into four sub-tabs:
 *   - routes    — every route across http.servers.* with host matcher + handler chain
 *   - upstreams — reverse_proxy backends with healthy/fail/num_requests counters
 *   - certs     — TLS automation policies + issued certs (when the local CA is up)
 *   - admin     — versions, listening sockets, raw config size
 */
class CaddyLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'caddy';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $output = $ssh->exec($this->privilegedCommand($server, $this->buildProbeScript()), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('Caddy admin API SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $config = $this->decodeJson($sections['config'] ?? '');
        $upstreams = $this->decodeJson($sections['upstreams'] ?? '');
        $caInfo = $this->decodeJson($sections['ca'] ?? '');
        $caddyVersion = trim($sections['version'] ?? '');

        if ($config === [] && empty($errors)) {
            $errors[] = 'Caddy admin API unreachable on 127.0.0.1:2019. Set `admin off` deliberately? Then probe is disabled.';
        }

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'routes' => $this->buildRouteUnits($config),
                'upstreams' => $this->buildUpstreamUnits($upstreams),
                'certs' => $this->buildCertUnits($config, $caInfo),
                'admin' => $this->buildAdminUnits($config, $caddyVersion, $output),
            ],
            engineSpecific: array_filter([
                'config_bytes' => is_string($output) ? strlen($sections['config'] ?? '') : null,
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null),
        );
    }

    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
URL="http://127.0.0.1:2019"
echo '###dply-section:version###'
caddy version 2>/dev/null | head -n 1
echo '###dply-section:end###'
echo '###dply-section:config###'
curl -fsS --max-time 5 "$URL/config/" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:upstreams###'
curl -fsS --max-time 5 "$URL/reverse_proxy/upstreams" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:ca###'
curl -fsS --max-time 5 "$URL/pki/ca/local" 2>/dev/null
echo
echo '###dply-section:end###'
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = ['version', 'config', 'upstreams', 'ca'];
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
     * Flatten every route across every HTTP server into one list. Each route
     * surfaces its first host matcher (for display), the listen addresses of
     * the parent server, and a short summary of the handler chain.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function buildRouteUnits(array $config): array
    {
        $servers = $config['apps']['http']['servers'] ?? [];
        if (! is_array($servers)) {
            return [];
        }
        $rows = [];
        foreach ($servers as $serverName => $serverCfg) {
            if (! is_array($serverCfg)) {
                continue;
            }
            $listen = is_array($serverCfg['listen'] ?? null) ? array_map('strval', $serverCfg['listen']) : [];
            foreach (($serverCfg['routes'] ?? []) as $idx => $route) {
                if (! is_array($route)) {
                    continue;
                }
                $hostMatch = $this->extractHostMatcher($route);
                $handlers = $this->summariseHandlers($route);
                $rows[] = [
                    'server' => (string) $serverName,
                    'listen' => $listen,
                    'host' => $hostMatch,
                    'handlers' => $handlers,
                    'terminal' => (bool) ($route['terminal'] ?? false),
                    'index' => (int) $idx,
                ];
            }
        }

        return $rows;
    }

    /**
     * The first @host matcher set found in the route. Caddy stores matchers
     * as `match: [{ host: [...], path: [...] }, ...]`; the host list is what
     * the operator recognises.
     *
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function extractHostMatcher(array $route): array
    {
        $matches = $route['match'] ?? [];
        if (! is_array($matches)) {
            return [];
        }
        foreach ($matches as $m) {
            if (is_array($m) && isset($m['host']) && is_array($m['host'])) {
                return array_values(array_map('strval', $m['host']));
            }
        }

        return [];
    }

    /**
     * Compact list of handler `handler` keys (reverse_proxy, file_server,
     * subroute, headers, …). Subroutes are recursed one level so a typical
     * "reverse_proxy under subroute" route surfaces "reverse_proxy" rather
     * than just "subroute" — which is what an operator wants to see.
     *
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function summariseHandlers(array $route): array
    {
        $out = [];
        foreach (($route['handle'] ?? []) as $h) {
            if (! is_array($h)) {
                continue;
            }
            $kind = (string) ($h['handler'] ?? '');
            if ($kind === '') {
                continue;
            }
            if ($kind === 'subroute' && is_array($h['routes'] ?? null)) {
                foreach ($h['routes'] as $sub) {
                    if (is_array($sub)) {
                        foreach ($this->summariseHandlers($sub) as $k) {
                            $out[] = $k;
                        }
                    }
                }

                continue;
            }
            $out[] = $kind;
        }

        return array_values(array_unique($out));
    }

    /**
     * /reverse_proxy/upstreams returns a list of:
     *   { address, healthy, num_requests, fails }
     *
     * @param  array<int, array<string, mixed>>  $upstreams
     * @return list<array<string, mixed>>
     */
    private function buildUpstreamUnits(array $upstreams): array
    {
        if ($upstreams === [] || ! isset($upstreams[0])) {
            return [];
        }
        $rows = [];
        foreach ($upstreams as $u) {
            if (! is_array($u)) {
                continue;
            }
            $rows[] = [
                'address' => (string) ($u['address'] ?? '?'),
                'healthy' => (bool) ($u['healthy'] ?? false),
                'num_requests' => (int) ($u['num_requests'] ?? 0),
                'fails' => (int) ($u['fails'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * TLS automation policies (Caddy's auto-HTTPS config) merged with local
     * CA info. Caddy's full cert inventory lives on disk under
     * /var/lib/caddy/.local/share/caddy/certificates/ — surfacing every
     * issued cert would require parsing that tree; for v1 we surface the
     * policies + the internal CA root cert subject so the operator can see
     * what Caddy is set up to issue.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $caInfo
     * @return list<array<string, mixed>>
     */
    private function buildCertUnits(array $config, array $caInfo): array
    {
        $rows = [];
        $policies = $config['apps']['tls']['automation']['policies'] ?? [];
        if (is_array($policies)) {
            foreach ($policies as $idx => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $subjects = is_array($p['subjects'] ?? null) ? array_map('strval', $p['subjects']) : [];
                $issuer = $this->extractIssuerName($p);
                $rows[] = [
                    'kind' => 'policy',
                    'name' => 'policy#'.$idx,
                    'subjects' => $subjects,
                    'issuer' => $issuer,
                    'on_demand' => (bool) ($p['on_demand'] ?? false),
                    'status' => 'configured',
                ];
            }
        }

        if (is_array($caInfo) && ($caInfo['id'] ?? null) !== null) {
            $rows[] = [
                'kind' => 'local_ca',
                'name' => (string) ($caInfo['name'] ?? 'local'),
                'subjects' => [(string) ($caInfo['root_common_name'] ?? '')],
                'issuer' => (string) ($caInfo['intermediate_common_name'] ?? ''),
                'on_demand' => false,
                'status' => 'active',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function extractIssuerName(array $policy): string
    {
        $issuers = $policy['issuers'] ?? [];
        if (! is_array($issuers)) {
            return '';
        }
        foreach ($issuers as $i) {
            if (is_array($i) && isset($i['module'])) {
                return (string) $i['module'];
            }
        }

        return '';
    }

    /**
     * Misc "admin / runtime" facts shown on the Admin sub-tab:
     *   - Caddy version (from `caddy version`)
     *   - Active config size
     *   - Listening sockets parsed out of every http.servers.*.listen
     *   - Admin endpoint state (always 127.0.0.1:2019 when probe succeeds)
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, string>>
     */
    private function buildAdminUnits(array $config, string $caddyVersion, string $rawOutput): array
    {
        $rows = [];
        $rows[] = ['key' => 'version', 'value' => $caddyVersion !== '' ? $caddyVersion : '?'];
        $rows[] = ['key' => 'admin', 'value' => $config !== [] ? '127.0.0.1:2019' : 'unreachable'];

        $listens = [];
        $servers = $config['apps']['http']['servers'] ?? [];
        if (is_array($servers)) {
            foreach ($servers as $sCfg) {
                if (is_array($sCfg) && is_array($sCfg['listen'] ?? null)) {
                    foreach ($sCfg['listen'] as $l) {
                        $listens[] = (string) $l;
                    }
                }
            }
        }
        $rows[] = ['key' => 'listeners', 'value' => $listens !== [] ? implode(', ', array_unique($listens)) : '—'];

        $configSize = strlen($rawOutput);
        $rows[] = ['key' => 'probe_bytes', 'value' => number_format($configSize).' B'];

        return $rows;
    }
}
