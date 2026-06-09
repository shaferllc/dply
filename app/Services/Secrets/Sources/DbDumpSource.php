<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A logical control-plane Postgres dump. The single pg_dump implementation lives
 * in deploy/secrets/db-backup.sh (so cron, deploy, and artisan share one code
 * path); this source shells its `--plaintext-stdout` mode and lets SecretVault
 * do the age-encryption + storage. The CANONICAL scheduled DB backup is the
 * bash cron itself (works when the app is down); this exists for the artisan
 * wrapper + the reusable seam.
 */
final class DbDumpSource implements SecretSource
{
    public function __construct(private readonly string $scriptPath) {}

    public function name(): string
    {
        return 'db-dump';
    }

    public function gather(Scope $scope): string
    {
        if (! is_file($this->scriptPath)) {
            throw new RuntimeException("db-backup.sh not found: {$this->scriptPath}");
        }

        $result = Process::timeout(600)->run(['bash', $this->scriptPath, '--plaintext-stdout']);

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
