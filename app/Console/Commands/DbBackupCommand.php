<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper over deploy/secrets/db-backup.sh so the SINGLE pg_dump
 * implementation lives in bash (identical whether triggered by cron, deploy, or
 * artisan, and runnable when the app is down — the cron calls bash directly,
 * never this command). Use `--stdout` to stream the age-encrypted dump.
 */
class DbBackupCommand extends Command
{
    protected $signature = 'db:backup {--stdout : stream the encrypted dump to stdout instead of uploading}';

    protected $description = 'Back up the control-plane database (age-encrypted) via the bash guard.';

    public function handle(): int
    {
        $script = base_path('deploy/secrets/db-backup.sh');
        if (! is_file($script)) {
            $this->error("db-backup.sh not found at {$script}.");

            return self::FAILURE;
        }

        $args = ['bash', $script];
        if ($this->option('stdout')) {
            $args[] = '--stdout';
        }

        $result = Process::timeout(900)->run($args, function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
