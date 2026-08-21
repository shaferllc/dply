<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Console\Concerns\ResolvesManagedDatabase;
use App\Modules\Database\Services\ManagedDatabaseBackups;
use Illuminate\Console\Command;
use Throwable;

/**
 * Build a new database from another one's backup.
 *
 *   dply:cloud:db:restore <database> <new-name> [--backup=<created_at>]
 *
 * Restore never rewinds the source — it creates a second cluster, billed
 * separately, that you inspect and attach yourself. Without --backup the most
 * recent restore point is used; {@see CloudDatabaseBackupsCommand} lists them.
 */
class CloudDatabaseRestoreCommand extends Command
{
    use ResolvesManagedDatabase;

    protected $signature = 'dply:cloud:db:restore
        {database : Source managed database ID or name}
        {name : Name for the restored database}
        {--backup= : Backup timestamp to restore from (defaults to the newest)}';

    protected $description = 'Restore a managed database backup into a new database.';

    public function handle(ManagedDatabaseBackups $backups): int
    {
        $needle = (string) $this->argument('database');
        $source = $this->resolveManagedDatabase($needle);
        if ($source === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        $backupAt = trim((string) $this->option('backup'));
        if ($backupAt === '') {
            $available = $backups->list($source);
            if ($available === []) {
                $this->error('No backups are available to restore from.');

                return self::FAILURE;
            }
            $backupAt = $available[0]['created_at'];
        }

        try {
            $restored = $backups->restore($source, (string) $this->argument('name'), $backupAt);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restore queued: "%s" (backup %s) → "%s" [%s].',
            $source->name,
            $backupAt,
            $restored->name,
            $restored->id,
        ));
        $this->line('<fg=gray>It is billed as a second cluster and is not attached to anything.</>');

        return self::SUCCESS;
    }
}
