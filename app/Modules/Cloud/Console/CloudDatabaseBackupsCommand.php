<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Console\Concerns\ResolvesManagedDatabase;
use App\Modules\Database\Services\ManagedDatabaseBackups;
use Illuminate\Console\Command;

/**
 * Restore points on a managed database.
 *
 *   dply:cloud:db:backups <database> [--json]
 *
 * The `created_at` values printed here are the handles
 * {@see CloudDatabaseRestoreCommand} takes.
 */
class CloudDatabaseBackupsCommand extends Command
{
    use ResolvesManagedDatabase;

    protected $signature = 'dply:cloud:db:backups
        {database : Managed database ID or name}
        {--json : Output as JSON}';

    protected $description = 'List the automatic backups on a managed database.';

    public function handle(ManagedDatabaseBackups $backups): int
    {
        $needle = (string) $this->argument('database');
        $database = $this->resolveManagedDatabase($needle);
        if ($database === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        if (! $backups->supports($database)) {
            $this->error("The {$database->backend} backend does not expose its backups to dply.");

            return self::FAILURE;
        }

        $rows = $backups->list($database);

        if ($this->option('json')) {
            $this->line(json_encode(['total' => count($rows), 'backups' => $rows], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No backups reported. A new cluster has none until its first scheduled run.</>');

            return self::SUCCESS;
        }

        $this->table(
            ['taken', 'size (GB)'],
            array_map(fn (array $b): array => [$b['created_at'], number_format($b['size_gigabytes'], 2)], $rows),
        );

        return self::SUCCESS;
    }
}
