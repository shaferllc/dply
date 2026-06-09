<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A logical dump of the control-plane Postgres, produced in-process with
 * `pg_dump` (no bash dependency — app-native per the dogfood direction).
 * SecretVault age-encrypts + stores the result.
 */
final class DbDumpSource implements SecretSource
{
    public function __construct(private readonly ?string $connection = null) {}

    public function name(): string
    {
        return 'db-dump';
    }

    public function gather(Scope $scope): string
    {
        $conn = $this->connection ?? (string) config('database.default');
        $cfg = (array) config("database.connections.{$conn}");

        if (($cfg['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("db-dump only supports pgsql (got {$conn}).");
        }

        $cmd = [
            'pg_dump',
            '--host='.(string) ($cfg['host'] ?? '127.0.0.1'),
            '--port='.(string) ($cfg['port'] ?? 5432),
            '--username='.(string) ($cfg['username'] ?? ''),
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
            (string) ($cfg['database'] ?? ''),
        ];

        $result = Process::timeout(900)
            ->env(['PGPASSWORD' => (string) ($cfg['password'] ?? '')])
            ->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException('pg_dump failed: '.trim($result->errorOutput()));
        }

        $dump = $result->output();
        if (trim($dump) === '') {
            throw new RuntimeException('pg_dump produced no output.');
        }

        return $dump;
    }
}
