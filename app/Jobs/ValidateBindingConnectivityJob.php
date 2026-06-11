<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a just-connected resource binding is actually reachable from
 * the site's server. Connectivity has to be tested from the box that will dial
 * the resource at runtime (not the control plane, which usually isn't on the
 * private network), so this opens SSH to the site's server and tries a TCP
 * socket to the binding's host:port. The result streams into the page-top
 * console banner and is recorded on the binding (config.connectivity +
 * last_error) so the UI can flag an unreachable connection.
 *
 * TCP-level only for v1 — it answers "can this server open a socket to the
 * service?", which is the cross-network reachability concern. Auth/protocol
 * validation (redis-cli PING, pg_isready) is a future refinement.
 */
class ValidateBindingConnectivityJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $bindingId,
    ) {}

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $binding = SiteBinding::query()->find($this->bindingId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($site === null || $binding === null || $action === null || $site->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $conn = null;

        try {
            [$host, $port] = $this->target($site, $binding);

            if ($host === null || $port === null) {
                $emit->info('No reachable host/port for this binding (it may use a file/array/sync driver) — nothing to probe.');
                $this->finish($emit, $binding, true, null);

                return;
            }

            $emit->step('probe', sprintf('From %s — opening TCP to %s:%d …', (string) $site->server->name, $host, $port));

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(10)) {
                throw new \RuntimeException('Could not open SSH to '.$site->server->name.'.');
            }

            // Pure-bash TCP probe: no client binary needed, works for any engine.
            $cmd = sprintf(
                "timeout 5 bash -c '</dev/tcp/%s/%d' >/dev/null 2>&1 && echo DPLY_PROBE_OK || echo DPLY_PROBE_FAIL",
                $host,
                $port,
            );
            $out = $conn->exec($cmd, 15);
            $reachable = str_contains($out, 'DPLY_PROBE_OK');

            if ($reachable) {
                $emit->success('probe', sprintf('Reachable — %s can open %s:%d.', (string) $site->server->name, $host, $port));
                $this->finish($emit, $binding, true, null);
            } else {
                $detail = sprintf('%s could not reach %s:%d.', (string) $site->server->name, $host, $port);
                $emit->warn($detail, 'probe');
                $emit->step('probe', 'Common causes: the service isn\'t listening on that address/port, it isn\'t bound to the private interface, an in-host firewall blocks the port, or remote access isn\'t allowed for this server\'s private IP.');
                $this->finish($emit, $binding, false, $detail);
            }
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $emit->error('Connectivity check failed: '.$message, 'probe');
            $this->finish($emit, $binding, false, 'Connectivity check did not complete: '.$message, failed: true);
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    /**
     * Host + port to probe for this binding.
     *
     * database/redis carry their own host:port. cache/queue/session are config
     * bindings with no endpoint of their own — they ride on an underlying engine
     * (CACHE_STORE / QUEUE_CONNECTION / SESSION_DRIVER = redis|database), so we
     * resolve that driver and read the REDIS or DB host:port from the site's
     * merged binding env (the redis/database binding supplies them). Non-networked
     * drivers (file/array/sync/cookie) and other types return nulls and skip.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function target(Site $site, SiteBinding $binding): array
    {
        $env = $binding->connectionEnv();

        if (in_array($binding->type, ['cache', 'queue', 'session'], true)) {
            $driverKey = match ($binding->type) {
                'cache' => 'CACHE_STORE',
                'queue' => 'QUEUE_CONNECTION',
                'session' => 'SESSION_DRIVER',
            };
            $driver = strtolower(trim((string) ($env[$driverKey] ?? $env['CACHE_DRIVER'] ?? '')));

            if (! in_array($driver, ['redis', 'database'], true)) {
                return [null, null]; // file/array/sync/cookie/null — nothing to probe
            }

            $env = $this->mergedConnectionEnv($site);
            [$hostKey, $portKey] = $driver === 'redis' ? ['REDIS_HOST', 'REDIS_PORT'] : ['DB_HOST', 'DB_PORT'];
            $defaultPort = $driver === 'redis' ? 6379 : 0;
        } else {
            [$hostKey, $portKey] = match ($binding->type) {
                'database' => ['DB_HOST', 'DB_PORT'],
                'redis' => ['REDIS_HOST', 'REDIS_PORT'],
                default => [null, null],
            };
            $defaultPort = 0;
        }

        if ($hostKey === null) {
            return [null, null];
        }

        $host = trim((string) ($env[$hostKey] ?? ''));
        $port = (int) ($env[$portKey] ?? 0) ?: $defaultPort;

        // Guard the shell interpolation: hostnames/IPs only, valid TCP port.
        if ($host === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1) {
            return [null, null];
        }
        if ($port < 1 || $port > 65535) {
            return [null, null];
        }

        return [$host, $port];
    }

    /**
     * All bindings' connection variables merged into one map, so a config
     * binding (cache/queue/session) can read the REDIS or DB host:port that the
     * underlying redis/database binding injects.
     *
     * @return array<string, string>
     */
    private function mergedConnectionEnv(Site $site): array
    {
        $merged = [];
        foreach ($site->bindings as $sibling) {
            foreach ($sibling->connectionEnv() as $key => $value) {
                $merged[$key] = (string) $value;
            }
        }

        return $merged;
    }

    private function finish(ConsoleEmitter $emit, SiteBinding $binding, bool $ok, ?string $error, bool $failed = false): void
    {
        $config = is_array($binding->config) ? $binding->config : [];
        $config['connectivity'] = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'detail' => $error,
        ];
        $binding->forceFill([
            'config' => $config,
            'last_error' => $ok ? null : $error,
        ])->save();

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
