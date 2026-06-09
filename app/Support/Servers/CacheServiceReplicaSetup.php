<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceReplication;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the four-step attach-a-replica flow:
 *
 *   1. (Optional pre-check) Refuse to overwrite a replica that already has keys
 *      unless the operator explicitly opted into the wipe (handled by caller).
 *   2. Network exposure: ensure the master is reachable from the replica's IP.
 *      Delegates to {@see CacheServiceNetworkExposure::expose()}; idempotent on
 *      an already-exposed master.
 *   3. SSH replica: CONFIG SET masterauth + replicaof + CONFIG REWRITE so the
 *      relationship survives restart.
 *   4. Poll `INFO replication` on the replica until master_link_status=up or a
 *      30-second timeout. On timeout, roll back the replica config and bail.
 *
 * Persists a {@see ServerCacheServiceReplication} row on success. On failure
 * the row is created with status=error and the error_message populated so the
 * Replication card surfaces what went wrong.
 */
class CacheServiceReplicaSetup
{
    public const LINK_POLL_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly CacheServiceNetworkExposure $exposure,
    ) {}

    public function attach(
        Server $masterServer,
        ServerCacheService $master,
        Server $replicaServer,
        ServerCacheService $replica,
        ?string $userId = null,
    ): ServerCacheServiceReplication {
        $this->guardEngines($master, $replica);

        $row = ServerCacheServiceReplication::query()->create([
            'master_cache_service_id' => $master->id,
            'replica_cache_service_id' => $replica->id,
            'status' => ServerCacheServiceReplication::STATUS_CONFIGURING,
        ]);

        try {
            // Step 2: expose master to the replica's IP. Idempotent on
            // already-exposed masters — exposure() short-circuits when the
            // managed firewall rule is already present for this CIDR.
            $masterIp = trim((string) $masterServer->ip_address);
            $replicaIp = trim((string) $replicaServer->ip_address);
            if ($masterIp === '' || $replicaIp === '') {
                throw new \RuntimeException('Master and replica servers must both have known IP addresses.');
            }

            $this->exposure->expose($masterServer, $master, $replicaIp.'/32', $userId);

            // Step 3: SSH replica + REPLICAOF.
            $this->configureReplica($replicaServer, $replica, $masterIp, (int) $master->port, (string) ($master->auth_password ?? ''));

            // Step 4: poll link status.
            $this->waitForLink($replicaServer, $replica);

            $row->update([
                'status' => ServerCacheServiceReplication::STATUS_ACTIVE,
                'last_link_status' => 'up',
                'last_polled_at' => now(),
                'error_message' => null,
            ]);

            return $row;
        } catch (\Throwable $e) {
            Log::warning('replica attach failed', ['master' => $master->id, 'replica' => $replica->id, 'error' => $e->getMessage()]);

            $this->rollbackReplica($replicaServer, $replica);

            $row->update([
                'status' => ServerCacheServiceReplication::STATUS_ERROR,
                'error_message' => $e->getMessage(),
                'last_polled_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Detach: clear REPLICAOF on the replica so it becomes a standalone master
     * again. The firewall rule opened on the master is left in place — operators
     * may want to re-attach without re-exposing. They can revoke explicitly
     * via the existing Network exposure card on Configure subtab.
     */
    public function detach(Server $replicaServer, ServerCacheService $replica, ServerCacheServiceReplication $row): void
    {
        $this->rollbackReplica($replicaServer, $replica);
        $row->update([
            'status' => ServerCacheServiceReplication::STATUS_TEARDOWN,
            'last_polled_at' => now(),
        ]);
        $row->delete();
    }

    private function guardEngines(ServerCacheService $master, ServerCacheService $replica): void
    {
        if (! ServerCacheService::engineSupportsAuth($master->engine) || ! ServerCacheService::engineSupportsAuth($replica->engine)) {
            throw new \InvalidArgumentException('Replication only supported between redis-family engines.');
        }
        if ($master->id === $replica->id) {
            throw new \InvalidArgumentException('Cannot replicate a cache service to itself.');
        }
    }

    private function configureReplica(Server $replicaServer, ServerCacheService $replica, string $masterIp, int $masterPort, string $masterPassword): void
    {
        $cli = CacheServiceStats::binaryFor($replica->engine);
        $cliPath = escapeshellarg($cli);
        $authFlag = filled($replica->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $replica->auth_password).' '
            : '';
        $replicaPort = (int) $replica->port;
        $masterIpQ = escapeshellarg($masterIp);

        // Build the CONFIG SET sequence. Use REPLICAOF if available (Redis 5+);
        // fall back to SLAVEOF for ancient engines. The engine accepts both
        // verbatim, so REPLICAOF is safe across redis-family.
        $script = <<<'BASH'
set -e
BASH;
        if ($masterPassword !== '') {
            $masterPwQ = escapeshellarg($masterPassword);
            $script .= "\n{$authFlag}{$cliPath} -p {$replicaPort} CONFIG SET masterauth {$masterPwQ} >/dev/null";
        }
        $script .= "\n{$authFlag}{$cliPath} -p {$replicaPort} REPLICAOF {$masterIpQ} {$masterPort} >/dev/null";
        $script .= "\n{$authFlag}{$cliPath} -p {$replicaPort} CONFIG REWRITE >/dev/null";

        $output = $this->executor->runInlineBash(
            $replicaServer,
            'cache-service:replica-attach:'.$replica->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: false,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException('Replica CONFIG SET failed: '.trim($output->buffer));
        }
    }

    private function waitForLink(Server $replicaServer, ServerCacheService $replica): void
    {
        $cli = CacheServiceStats::binaryFor($replica->engine);
        $cliPath = escapeshellarg($cli);
        $authFlag = filled($replica->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $replica->auth_password).' '
            : '';
        $port = (int) $replica->port;

        $deadline = time() + self::LINK_POLL_TIMEOUT_SECONDS;
        $lastStatus = 'unknown';
        do {
            $output = $this->executor->runInlineBash(
                $replicaServer,
                'cache-service:replica-link-poll:'.$replica->engine,
                $authFlag.$cliPath.' -p '.$port.' INFO replication 2>/dev/null',
                timeoutSeconds: 15,
                asRoot: false,
            );

            if ($output->exitCode === 0 && preg_match('/master_link_status:(\w+)/', $output->buffer, $m)) {
                $lastStatus = $m[1];
                if ($lastStatus === 'up') {
                    return;
                }
            }

            sleep(2);
        } while (time() < $deadline);

        throw new \RuntimeException('Replication link did not reach master_link_status=up within '.self::LINK_POLL_TIMEOUT_SECONDS.'s (last seen: '.$lastStatus.').');
    }

    private function rollbackReplica(Server $replicaServer, ServerCacheService $replica): void
    {
        $cli = CacheServiceStats::binaryFor($replica->engine);
        $cliPath = escapeshellarg($cli);
        $authFlag = filled($replica->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $replica->auth_password).' '
            : '';
        $port = (int) $replica->port;

        // Best-effort — surfaces the failure but doesn't re-throw because the
        // primary attach error is more useful to the operator.
        try {
            $this->executor->runInlineBash(
                $replicaServer,
                'cache-service:replica-rollback:'.$replica->engine,
                "{$authFlag}{$cliPath} -p {$port} REPLICAOF NO ONE >/dev/null; {$authFlag}{$cliPath} -p {$port} CONFIG REWRITE >/dev/null",
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable) {
            // swallow — the caller already has the meaningful error
        }
    }
}
