<?php

namespace App\Services\WorkerPools;

use App\Models\Server;

/**
 * Rewrites a worker site's .env for a cross-region/cross-provider clone.
 *
 * A same-region clone shares the source's private network, so private service
 * IPs (REDIS_HOST=10.0.0.4, DB_HOST=10.0.0.5, …) resolve verbatim. A clone in
 * another region/provider has no private path, so each env value pointing at an
 * in-org server's private IP must be rewritten to that server's PUBLIC address —
 * and that backend must be exposed (TLS) + the clone allowlisted.
 *
 * This service does the deterministic half: detect those references, rewrite the
 * hosts, and return an exposure PLAN (which backend server, which ports, which
 * clone IP) for the operator/automation to act on. It does NOT open firewalls or
 * bind databases publicly itself — that stays an explicit, confirmed step.
 */
class PoolEnvTransformer
{
    /**
     * @return array{env: string, exposures: list<array{server_id:string,server_name:string,public_ip:string,private_ip:string,ports:list<int>,keys:list<string>}>}
     */
    /** @return array<string, mixed> */
    public function rewriteForCrossRegion(string $env, Server $clone): array
    {
        if (trim($env) === '') {
            return ['env' => $env, 'exposures' => []];
        }

        $orgId = $clone->organization_id;
        $cloneIp = (string) ($clone->ip_address ?? '');

        // Map of private IP → backend Server (within the org), built lazily.
        $byPrivateIp = Server::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('private_ip_address')
            ->whereKeyNot($clone->id)
            ->get(['id', 'name', 'ip_address', 'private_ip_address'])
            ->keyBy(fn (Server $s): string => (string) $s->private_ip_address);

        if ($byPrivateIp->isEmpty()) {
            return ['env' => $env, 'exposures' => []];
        }

        // Parse into key=>value plus keep original lines so we can rewrite in place.
        $lines = preg_split('/\r\n|\r|\n/', $env) ?: [];
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                $values[$m[1]] = $this->unquote($m[2]);
            }
        }

        /** @var array<string, array{server_id:string,server_name:string,public_ip:string,private_ip:string,ports:array<int,int>,keys:array<int,string>}> $exposures keyed by server_id */
        $exposures = [];

        $rewrite = function (string $line) use ($byPrivateIp, $values, &$exposures): string {
            if (! preg_match('/^(\s*)([A-Z0-9_]+)(\s*=\s*)(.*)$/', $line, $m)) {
                return $line;
            }
            [$full, $indent, $key, $eq, $rawValue] = $m;
            $value = $this->unquote($rawValue);

            foreach ($byPrivateIp as $privateIp => $backend) {
                if ($privateIp === '' || ! str_contains($value, $privateIp)) {
                    continue;
                }
                $public = (string) ($backend->ip_address ?? '');
                if ($public === '') {
                    // Backend has no public IP — can't rewrite; leave + still flag.
                    $this->recordExposure($exposures, $backend, $privateIp, $key, $values);

                    return $line;
                }

                $newValue = str_replace($privateIp, $public, $value);
                $this->recordExposure($exposures, $backend, $privateIp, $key, $values);

                return $indent.$key.$eq.$this->requote($rawValue, $newValue);
            }

            return $line;
        };

        $out = array_map($rewrite, $lines);

        return [
            'env' => implode("\n", $out),
            'exposures' => array_values(array_map(static function (array $e): array {
                $e['ports'] = array_values(array_unique($e['ports']));
                $e['keys'] = array_values(array_unique($e['keys']));

                return $e;
            }, $exposures)),
        ];
    }

    /**
     * Convenience for callers that only need the plan (e.g. UI preview).
     *
     * @return list<array{server_id:string,server_name:string,public_ip:string,private_ip:string,ports:list<int>,keys:list<string>}>
     */
    /** @return array<string, mixed> */
    public function exposurePlan(string $env, Server $clone): array
    {
        return $this->rewriteForCrossRegion($env, $clone)['exposures'];
    }

    /**
     * @param  array<string, array{server_id:string,server_name:string,public_ip:string,private_ip:string,ports:array<int,int>,keys:array<int,string>}>  $exposures
     * @param  array<string, mixed> $values
     */
    private function recordExposure(array &$exposures, Server $backend, string $privateIp, string $key, array $values): void
    {
        $id = (string) $backend->id;
        $exposures[$id] ??= [
            'server_id' => $id,
            'server_name' => (string) $backend->name,
            'public_ip' => (string) ($backend->ip_address ?? ''),
            'private_ip' => $privateIp,
            'ports' => [],
            'keys' => [],
        ];
        $exposures[$id]['keys'][] = $key;

        $port = $this->companionPort($key, $values);
        if ($port !== null) {
            $exposures[$id]['ports'][] = $port;
        }
    }

    /**
     * Best-effort port for a *_HOST key: prefer its sibling *_PORT, else a
     * well-known default for common services.
     *
     * @param  array<string, mixed> $values
     */
    private function companionPort(string $key, array $values): ?int
    {
        if (str_ends_with($key, '_HOST')) {
            $portKey = substr($key, 0, -5).'_PORT';
            if (isset($values[$portKey]) && is_numeric($values[$portKey])) {
                return (int) $values[$portKey];
            }
        }

        return match (true) {
            str_starts_with($key, 'REDIS') => 6379,
            str_starts_with($key, 'DB') => 5432,
            str_starts_with($key, 'MEMCACHED') => 11211,
            default => null,
        };
    }

    private function unquote(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            return substr($v, 1, -1);
        }

        return $v;
    }

    private function requote(string $rawValue, string $newInner): string
    {
        $t = trim($rawValue);
        if (strlen($t) >= 2 && ($t[0] === '"' || $t[0] === "'") && $t[-1] === $t[0]) {
            return $t[0].$newInner.$t[0];
        }

        return $newInner;
    }
}
