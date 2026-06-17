<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerDatabaseBackup;
use App\Services\Servers\ServerDatabaseBackupRestorer;
use Illuminate\Console\Command;

/**
 * Restore a database backup (W3). DESTRUCTIVE — overwrites the target DB — so it
 * refuses without --force and confirms interactively. Runs synchronously (a CLI
 * operator action; no web timeout). For restoring INTO a fresh DB during DR,
 * pass --to-database to avoid clobbering the live one.
 */
class DbRestoreCommand extends Command
{
    protected $signature = 'dply:db:restore
        {backup : ServerDatabaseBackup id}
        {--to-database= : restore into this DB name instead of the backup source}
        {--force : required to actually run (destructive)}';

    protected $description = 'Restore a database backup into a target database (destructive).';

    public function handle(ServerDatabaseBackupRestorer $restorer): int
    {
        $backup = ServerDatabaseBackup::query()->find($this->argument('backup'));
        if ($backup === null) {
            $this->error('Backup not found.');

            return self::FAILURE;
        }

        $db = $backup->serverDatabase;
        $target = (string) ($this->option('to-database') ?: $db->name);
        if ($target === '') {
            $this->error('Could not resolve a target database.');

            return self::FAILURE;
        }

        $this->warn("This OVERWRITES database '{$target}' on server #{$db?->server_id} with backup {$backup->id}.");

        if (! $this->option('force')) {
            $this->error('Refusing without --force.');

            return self::FAILURE;
        }
        if (! $this->confirm("Type yes to overwrite '{$target}' now", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $restorer->restore($backup, $target);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored backup {$backup->id} into '{$target}'.");

        return self::SUCCESS;
    }
}
