<?php

declare(strict_types=1);

namespace App\Services\Remediations;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Support\Str;

/**
 * Level-A diagnosis for the `database_connection_failed` remediation: given a
 * failed deploy's output and the site, work out WHY the database couldn't be
 * reached and which guided fix to lead with — using only data dply already
 * holds (no SSH), so it is safe to run on render.
 *
 * It answers two questions and combines them:
 *   1. What kind of failure?  (refused / host unknown / auth / unknown db) —
 *      from the error text.
 *   2. What is the site's DB wiring?  (nothing attached / a DB is attached / it
 *      points at an external host / it's SQLite) — from attached databases +
 *      bindings + the DB_* it resolved into the env.
 */
class DatabaseConnectionDiagnostic
{
    public function __construct(private readonly DotEnvFileParser $parser) {}

    public function for(Site $site, string $failureText): DatabaseConnectionDiagnosis
    {
        $subclass = $this->classify($failureText);

        $vars = $this->resolveEnv($site);
        $connection = $this->cleanScalar($vars['DB_CONNECTION'] ?? null);
        $host = $this->cleanScalar($vars['DB_HOST'] ?? null);
        $port = $this->cleanScalar($vars['DB_PORT'] ?? null);

        $engineFamily = $this->engineFamily($site, $connection, $port);
        $state = $this->resolveState($site, $connection, $host);

        [$headline, $detail, $actions] = $this->recommend($subclass, $state, $host);

        return new DatabaseConnectionDiagnosis(
            subclass: $subclass,
            state: $state,
            engineFamily: $engineFamily,
            envHost: $host,
            envConnection: $connection,
            headline: $headline,
            detail: $detail,
            actions: $actions,
        );
    }

    /** Bucket the error text into the connection-failure family. Order = most specific first. */
    private function classify(string $text): string
    {
        return match (true) {
            (bool) preg_match('/password authentication failed|SQLSTATE\[28P01\]|Access denied for user|\[1045\]/i', $text) => DatabaseConnectionDiagnosis::SUBCLASS_AUTH_FAILED,
            (bool) preg_match('/database ".*" does not exist|SQLSTATE\[3D000\]|Unknown database|\[1049\]/i', $text) => DatabaseConnectionDiagnosis::SUBCLASS_UNKNOWN_DB,
            (bool) preg_match('/could not translate host name|getaddrinfo|name or service not known|php_network_getaddresses|No such host/i', $text) => DatabaseConnectionDiagnosis::SUBCLASS_HOST_UNKNOWN,
            default => DatabaseConnectionDiagnosis::SUBCLASS_REFUSED,
        };
    }

    /** @return array<string, string> resolved env variables (best-effort, no SSH) */
    private function resolveEnv(Site $site): array
    {
        try {
            $parsed = $this->parser->parse($site->effectiveEnvFileContent());

            return is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function cleanScalar(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function engineFamily(Site $site, ?string $connection, ?string $port): ?string
    {
        $conn = strtolower((string) $connection);
        if ($conn === 'pgsql' || $conn === 'postgres' || $conn === 'postgresql') {
            return 'postgres';
        }
        if ($conn === 'mysql' || $conn === 'mariadb') {
            return 'mysql';
        }
        if ($port === '5432') {
            return 'postgres';
        }
        if ($port === '3306') {
            return 'mysql';
        }

        $resolved = $site->databaseEngine();

        return match ($resolved) {
            'postgres', 'pgsql' => 'postgres',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };
    }

    private function resolveState(Site $site, ?string $connection, ?string $host): string
    {
        if (strtolower((string) $connection) === 'sqlite') {
            return DatabaseConnectionDiagnosis::STATE_SQLITE;
        }
        if ($this->hasAttachedDatabase($site)) {
            return DatabaseConnectionDiagnosis::STATE_ATTACHED;
        }
        if ($this->isRemoteHost($host)) {
            return DatabaseConnectionDiagnosis::STATE_REMOTE;
        }

        return DatabaseConnectionDiagnosis::STATE_NO_DB;
    }

    /** A managed database is wired to this site if it owns a ServerDatabase or a database binding. */
    private function hasAttachedDatabase(Site $site): bool
    {
        return $site->serverDatabases()->exists()
            || $site->bindings()->where('type', 'database')->exists();
    }

    /** Any non-loopback host the app is pointed at — i.e. not the box's own localhost. */
    private function isRemoteHost(?string $host): bool
    {
        if ($host === null) {
            return false;
        }

        return ! in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    /**
     * Pick the headline + ordered guided actions from state, then sharpen the
     * copy for the specific failure subclass.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function recommend(string $subclass, string $state, ?string $host): array
    {
        $hostLabel = $host ?: '127.0.0.1';

        // Binding exists but the connection still failed — Level A can't tell why
        // (engine down / firewall / drifted host:port), so be honest and route
        // rather than pretend "attach" or "inject" is the certain fix (Q12).
        if ($state === DatabaseConnectionDiagnosis::STATE_ATTACHED) {
            $detail = match ($subclass) {
                DatabaseConnectionDiagnosis::SUBCLASS_AUTH_FAILED => __('A database is attached, but the server rejected the credentials. The stored DB_USERNAME / DB_PASSWORD may have drifted from what’s on the server — re-inject them, or rotate the password from the Database tab.'),
                DatabaseConnectionDiagnosis::SUBCLASS_UNKNOWN_DB => __('A database is attached, but the database name the app uses doesn’t exist on the server. Check DB_DATABASE, or create the missing database from the Database tab.'),
                default => __('A database is attached, but the server refused the connection. The most common causes are that the database engine isn’t running, or DB_HOST / DB_PORT drifted. Check the engine on the Database tab, or re-inject the connection details.'),
            };

            return [
                __('A database is attached, but the connection failed'),
                $detail,
                [DatabaseConnectionDiagnosis::ACTION_OPEN_DATABASE, DatabaseConnectionDiagnosis::ACTION_INJECT],
            ];
        }

        if ($state === DatabaseConnectionDiagnosis::STATE_SQLITE) {
            return [
                __('The SQLite database couldn’t be opened'),
                __('The app is configured for SQLite but the database file couldn’t be opened. Check the DB_DATABASE path, or inject corrected database settings.'),
                [DatabaseConnectionDiagnosis::ACTION_INJECT],
            ];
        }

        if ($state === DatabaseConnectionDiagnosis::STATE_REMOTE) {
            return [
                __('The external database refused the connection'),
                __('The app points at an external database at :host that refused the connection. Confirm the host, port, and credentials are correct and reachable, then inject the corrected values — or attach a database managed by dply instead.', ['host' => $hostLabel]),
                [DatabaseConnectionDiagnosis::ACTION_INJECT, DatabaseConnectionDiagnosis::ACTION_ATTACH],
            ];
        }

        // STATE_NO_DB — the dominant case: nothing is attached and the app is
        // still pointed at the box's localhost default.
        $detail = match ($subclass) {
            DatabaseConnectionDiagnosis::SUBCLASS_HOST_UNKNOWN => __('No database is attached to this site, and DB_HOST (:host) can’t be resolved. Attach a database managed by dply to wire it up automatically, or inject the connection details yourself.', ['host' => $hostLabel]),
            default => __('No database is attached to this site — the app is still pointed at :host with no database provisioned there, so the connection is refused. Attach one and dply will provision it and wire the connection into your environment.', ['host' => $hostLabel]),
        };

        return [
            __('No database is attached to this site'),
            $detail,
            [DatabaseConnectionDiagnosis::ACTION_ATTACH, DatabaseConnectionDiagnosis::ACTION_INJECT],
        ];
    }
}
