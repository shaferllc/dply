<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\ServerDatabase;
use App\Models\ServerLogAggregator;

/**
 * Where the control plane reads dply Logs from.
 *
 * The edge side has always self-configured — VectorLogAgentInstallScripts
 * ::resolveAggregatorTarget() reads the live {@see ServerLogAggregator} row, so
 * an agent needs no manual env. The READ side did not: it took
 * config('server_logs.clickhouse') verbatim, whose host defaults to 127.0.0.1.
 * Stand up a log server, ship to it successfully, and the viewer still reported
 * "Log store unavailable" because dply was querying its own loopback — the one
 * place ClickHouse certainly is not.
 *
 * So resolve the same way the edge does: from the log server itself. Explicit
 * config still wins, because a deployment that fronts ClickHouse with a TLS
 * proxy, or a local docker-compose store, has a host dply cannot infer.
 *
 * Precedence:
 *   1. CLICKHOUSE_HOST (or any explicitly set CLICKHOUSE_* value) — manual wins.
 *   2. The running aggregator's server, with credentials from the ClickHouse
 *      `server_databases` row on that same box.
 *   3. config defaults (127.0.0.1) — the docker-compose dev store.
 */
final class LogStoreConnection
{
    /**
     * Effective ClickHouse settings, shaped exactly like config('server_logs.clickhouse').
     *
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        $cfg = (array) config('server_logs.clickhouse', []);

        // An explicit host means the operator has pointed us somewhere specific
        // (TLS proxy, managed store, docker). Never second-guess that.
        if (trim((string) ($cfg['host'] ?? '')) !== '') {
            return $cfg;
        }

        $aggregator = ServerLogAggregator::query()
            ->with('server')
            ->where('status', ServerLogAggregator::STATUS_RUNNING)
            ->orderByDesc('updated_at')
            ->first();

        $server = $aggregator?->server;

        if ($server === null) {
            $cfg['host'] = '127.0.0.1';

            return $cfg;
        }

        // Public address by default: the control plane is not necessarily on the
        // log server's VPC (it usually isn't in local development), whereas the
        // public IP is always routable — subject to the ClickHouse engine's
        // firewall, which is the intended boundary. A control plane that IS on
        // the VPC should set CLICKHOUSE_HOST to the private address.
        $host = trim((string) ($server->ip_address ?: $server->private_ip_address ?: ''));
        $cfg['host'] = $host !== '' ? $host : '127.0.0.1';

        // Credentials from the ClickHouse database dply created on that box,
        // unless they were set explicitly. `default` is the config fallback, so
        // treat it as "not set" rather than as a deliberate choice.
        $row = ServerDatabase::query()
            ->where('server_id', $server->id)
            ->where('engine', 'clickhouse')
            ->orderBy('name')
            ->first();

        if ($row !== null) {
            $usernameUnset = trim((string) ($cfg['username'] ?? '')) === ''
                || ($cfg['username'] ?? null) === 'default';

            if ($usernameUnset && trim((string) $row->username) !== '') {
                $cfg['username'] = $row->username;
                $cfg['password'] = (string) $row->password;
            }

            if (trim((string) ($cfg['database'] ?? '')) === '' || ($cfg['database'] ?? null) === 'dply_logs') {
                $cfg['database'] = $row->name;
            }
        }

        return $cfg;
    }

    /**
     * Human-readable target, for "could not reach X" messages. Never includes
     * credentials.
     */
    public static function describe(): string
    {
        $cfg = self::resolve();

        return sprintf(
            '%s://%s:%s',
            (string) ($cfg['scheme'] ?? 'http'),
            (string) ($cfg['host'] ?? '127.0.0.1'),
            (string) ($cfg['http_port'] ?? 8123),
        );
    }
}
