<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Console\Commands\SyncLogStoreSchemaCommand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client over ClickHouse's HTTP interface (port 8123) for the dply Logs
 * store. Laravel uses this ONLY for reads (the log explorer) and the one-time
 * DDL ({@see SyncLogStoreSchemaCommand}) — the high-volume
 * ingest path is the Vector aggregator inserting directly, never PHP.
 *
 * Reads bind parameters server-side via ClickHouse's `{name:Type}` placeholders
 * (passed as `param_<name>` query args) so org scoping can't be SQL-injected.
 * See docs/SERVER_LOGS_ADDON.md.
 */
class ClickHouseClient
{
    /**
     * Run a SELECT and return the decoded rows. The query should NOT include a
     * FORMAT clause — we append `FORMAT JSON` and unwrap `.data`.
     *
     * @param  array<string, scalar>  $params  Bound as ClickHouse {name:Type} params.
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $boundQuery = [];
        foreach ($params as $key => $value) {
            $boundQuery['param_'.$key] = $value;
        }

        $response = $this->http($this->database(), $boundQuery)
            ->withBody(rtrim($sql, "; \n").' FORMAT JSON', 'text/plain')
            ->post($this->baseUrl());

        $response->throw();

        $decoded = $response->json();

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * Run a single scalar SELECT (e.g. count). Returns the first column of the
     * first row, or null.
     *
     * @param  array<string, scalar>  $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $rows = $this->select($sql, $params);
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];

        return $first === [] ? null : array_values($first)[0];
    }

    /**
     * Execute a statement with no result set (DDL, etc.). Runs WITHOUT a current
     * database so `CREATE DATABASE` works before the database exists; tables are
     * referenced fully-qualified ({@see qualifiedTable()}).
     */
    public function statement(string $sql): void
    {
        $this->http(null)
            ->withBody($sql, 'text/plain')
            ->post($this->baseUrl())
            ->throw();
    }

    /**
     * Insert rows using the JSONEachRow format. Used by tests / seeders; the
     * aggregator does production inserts. Keys must match table columns.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $qualifiedTable, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $body = implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $rows,
        ));

        $this->http($this->database(), ['query' => "INSERT INTO {$qualifiedTable} FORMAT JSONEachRow"])
            ->withBody($body, 'application/x-ndjson')
            ->post($this->baseUrl())
            ->throw();
    }

    /**
     * Cheap connectivity check — true if ClickHouse answers `SELECT 1`.
     */
    public function ping(): bool
    {
        try {
            return (int) $this->scalar('SELECT 1') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function database(): string
    {
        return (string) config('server_logs.clickhouse.database', 'dply_logs');
    }

    public function table(): string
    {
        return (string) config('server_logs.clickhouse.table', 'server_logs');
    }

    /**
     * Fully-qualified `database.table` for the log store.
     */
    public function qualifiedTable(): string
    {
        return $this->database().'.'.$this->table();
    }

    /**
     * Build a configured HTTP request. `$database` null = no `database` query arg
     * (ClickHouse uses its default), required for DDL that creates the database.
     *
     * @param  array<string, scalar>  $extraQuery
     */
    protected function http(?string $database, array $extraQuery = []): PendingRequest
    {
        $cfg = (array) config('server_logs.clickhouse');

        $query = $extraQuery;
        if ($database !== null) {
            $query['database'] = $database;
        }

        $request = Http::withHeaders([
            'X-ClickHouse-User' => (string) ($cfg['username'] ?? 'default'),
            'X-ClickHouse-Key' => (string) ($cfg['password'] ?? ''),
        ])
            ->withQueryParameters($query)
            ->timeout((int) ($cfg['timeout'] ?? 15))
            ->acceptJson();

        // For https (cross-provider prod → DO via the TLS proxy) the cert is
        // signed by our private CA, so default system-CA verification would fail.
        // Verify against the supplied CA when present; otherwise honour the
        // explicit verify flag (false = encrypted-but-unverified, IP-locked only).
        if (($cfg['scheme'] ?? 'http') === 'https') {
            $caB64 = trim((string) ($cfg['ca_cert_b64'] ?? ''));
            if ($caB64 !== '') {
                $request = $request->withOptions(['verify' => $this->materializeCa($caB64)]);
            } elseif (($cfg['verify'] ?? true) === false) {
                $request = $request->withOptions(['verify' => false]);
            }
        }

        return $request;
    }

    /**
     * Write the base64 CA PEM to a stable file (Guzzle's `verify` wants a path)
     * and return it. Rewrites only when the content changes.
     */
    protected function materializeCa(string $b64): string
    {
        $pem = base64_decode($b64, true) ?: '';
        $path = storage_path('app/dply-clickhouse-ca.pem');
        if (! is_file($path) || md5_file($path) !== md5($pem)) {
            @file_put_contents($path, $pem);
        }

        return $path;
    }

    protected function baseUrl(): string
    {
        $cfg = (array) config('server_logs.clickhouse');
        $scheme = (string) ($cfg['scheme'] ?? 'http');
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['http_port'] ?? 8123);

        return "{$scheme}://{$host}:{$port}/";
    }
}
