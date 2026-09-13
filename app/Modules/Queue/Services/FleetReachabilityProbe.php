<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Server;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Str;
use Throwable;

/**
 * Can a worker on this fleet host reach the app's database and Redis?
 *
 * Asked from the fleet host, not from dply: the question is whether packets
 * leave the host and come back, and only the host can answer that. A worker
 * that cannot reach its database still claims jobs — it just fails every one,
 * which reads as broken code rather than a network. The result is kept on the
 * fleet (`meta.reachability.<host id>`) so the answer outlives the command.
 */
final class FleetReachabilityProbe
{
    public function __construct(
        private readonly FleetWorkerEnvironment $environment,
        private readonly ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return list<array{name: string, host: string, port: int, ok: bool, detail: string}>
     */
    public function check(ManagedQueueFleet $fleet, Server $host): array
    {
        $results = array_map(
            fn (array $target): array => $this->probe($host, $target),
            self::targets($this->environment->for($fleet)),
        );

        $meta = is_array($fleet->meta) ? $fleet->meta : [];
        $meta['reachability'][(string) $host->id] = [
            'checked_at' => now()->toIso8601String(),
            'targets' => $results,
        ];
        $fleet->forceFill(['meta' => $meta])->save();

        return $results;
    }

    /**
     * The hosts a worker will dial, read the way Laravel reads them.
     *
     * @param  array<string, string>  $env
     * @return list<array{name: string, host: string, port: int}>
     */
    public static function targets(array $env): array
    {
        $targets = [];

        $driver = strtolower(trim($env['DB_CONNECTION'] ?? ''));
        $dbHost = trim($env['DB_HOST'] ?? '');
        if ($driver !== '' && $driver !== 'sqlite' && $dbHost !== '') {
            $targets[] = [
                'name' => 'database',
                'host' => $dbHost,
                'port' => (int) ($env['DB_PORT'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            ];
        }

        $redisHost = trim($env['REDIS_HOST'] ?? '');
        if ($redisHost !== '') {
            $targets[] = ['name' => 'redis', 'host' => $redisHost, 'port' => (int) ($env['REDIS_PORT'] ?? 6379)];
        }

        return $targets;
    }

    /**
     * @param  array{name: string, host: string, port: int}  $target
     * @return array{name: string, host: string, port: int, ok: bool, detail: string}
     */
    public function probe(Server $host, array $target): array
    {
        $address = $target['host'];

        if ($address === 'localhost' || $address === '::1' || str_starts_with($address, '127.')) {
            return $target + ['ok' => false, 'detail' => __('points at the app server’s own loopback — a container on another host cannot reach it')];
        }

        // The value comes from the customer's .env and lands inside `bash -c`,
        // so anything that is not plainly a hostname or an address never runs.
        if (preg_match('/^[A-Za-z0-9._:-]{1,253}$/', $address) !== 1 || $target['port'] < 1 || $target['port'] > 65535) {
            return $target + ['ok' => false, 'detail' => __('not a host and port dply will dial')];
        }

        try {
            $out = $this->remote->runInlineBash(
                $host,
                'fleet-reachability',
                sprintf(
                    'timeout 5 bash -c %s 2>/dev/null && echo DPLY_REACH_OK || echo DPLY_REACH_NO',
                    escapeshellarg(sprintf('</dev/tcp/%s/%d', $address, $target['port'])),
                ),
                timeoutSeconds: 30,
            );
        } catch (Throwable $e) {
            return $target + ['ok' => false, 'detail' => __('the probe could not run: :m', ['m' => Str::limit($e->getMessage(), 200)])];
        }

        $ok = str_contains((string) $out->buffer, 'DPLY_REACH_OK');

        return $target + [
            'ok' => $ok,
            'detail' => $ok ? __('answers') : __('no answer within 5s — a firewall, the service’s bind address, or no route between the networks'),
        ];
    }
}
