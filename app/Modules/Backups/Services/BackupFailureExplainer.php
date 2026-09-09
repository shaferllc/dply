<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * Turn a raw dump/restore failure into a cause and a next step.
 *
 * `mysqldump: Got error: 2002: Can't connect to local MySQL server through
 * socket '/var/run/mysqld/mysqld.sock' (2)` is precise and useless — it does not
 * say the engine is stopped, and it does not say what to do. An operator should
 * not have to know driver error codes to find out their backups are not running.
 *
 * The raw text is never discarded; it is offered alongside the explanation, so
 * an unfamiliar failure stays diagnosable rather than being flattened into a
 * confident guess.
 */
final class BackupFailureExplainer
{
    /**
     * @return array{summary: string, action: ?string, raw: string}
     */
    public function explain(?string $error, ?string $engine = null, ?string $serverName = null): array
    {
        $raw = trim((string) $error);
        if ($raw === '') {
            return ['summary' => __('The run failed without reporting a reason.'), 'action' => null, 'raw' => ''];
        }

        $on = $serverName !== null && $serverName !== ''
            ? __(' on :server', ['server' => $serverName])
            : '';

        foreach ($this->rules($engine, $on) as [$pattern, $summary, $action]) {
            if (preg_match($pattern, $raw) === 1) {
                return ['summary' => $summary, 'action' => $action, 'raw' => $raw];
            }
        }

        // Unrecognised: hand back the original rather than pretend to know.
        return ['summary' => $raw, 'action' => null, 'raw' => $raw];
    }

    /**
     * Ordered most-specific first. A local socket failure and a remote TCP
     * failure both mean "cannot connect" but need completely different fixes,
     * so they are never collapsed together.
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private function rules(?string $engine, string $on): array
    {
        $engineLabel = match (true) {
            $engine === null => __('The database engine'),
            str_contains($engine, 'maria') => 'MariaDB',
            str_contains($engine, 'mysql') => 'MySQL',
            str_contains($engine, 'postg') => 'PostgreSQL',
            default => __('The database engine'),
        };

        return [
            // MySQL 2002 — no local socket. Almost always a stopped service.
            [
                '/\b2002\b|Can\'t connect to local (MySQL|MariaDB) server through socket/i',
                __(':engine is not running:on.', ['engine' => $engineLabel, 'on' => $on]),
                __('Start the service, or remove this schedule if the database is no longer used.'),
            ],
            // MySQL 2003 — TCP refused, i.e. wrong host or firewalled.
            [
                '/\b2003\b|Can\'t connect to (MySQL|MariaDB) server on/i',
                __(':engine refused the connection:on.', ['engine' => $engineLabel, 'on' => $on]),
                __('Check the host and port on the database, and that the engine accepts remote connections.'),
            ],
            // Postgres equivalents.
            [
                '/could not connect to server|Connection refused|server closed the connection unexpectedly/i',
                __(':engine is not accepting connections:on.', ['engine' => $engineLabel, 'on' => $on]),
                __('Start the service, or check the host and port on the database.'),
            ],
            [
                '/Access denied for user|password authentication failed|authentication failed for user/i',
                __('The stored credentials were rejected.'),
                __('The username or password has drifted from the server — update the database credentials and try again.'),
            ],
            [
                '/Unknown database|database "[^"]+" does not exist|FATAL:\s+database/i',
                __('That database no longer exists on the server.'),
                __('It was renamed or dropped — remove this schedule, or point it at the new name.'),
            ],
            [
                '/No space left on device|disk full|SQLSTATE\[HY000\].*full/i',
                __('The server ran out of disk space while writing the dump.'),
                __('Free space on the server, or ship dumps to a destination so they are not kept locally.'),
            ],
            [
                '/command not found|mysqldump: not found|pg_dump: not found/i',
                __('The dump tool is not installed:on.', ['on' => $on]),
                __('Install the matching client package for this engine on that server.'),
            ],
            [
                '/permission denied|Permission denied/i',
                __('The server refused access to a file or directory it needed.'),
                __('Check permissions on the backup directory.'),
            ],
            [
                '/timed out|timeout|Operation timed out/i',
                __('The dump timed out before it finished.'),
                __('A large database may need a longer window, or a quieter time of day.'),
            ],
            [
                '/produced an empty file|Backup produced an empty/i',
                __('The dump completed but produced no data.'),
                __('Check that the database has tables and that the user can read them.'),
            ],
        ];
    }
}
