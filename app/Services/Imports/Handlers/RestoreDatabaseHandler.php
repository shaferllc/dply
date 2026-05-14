<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Server;
use App\Models\Site;
use App\Services\Imports\Ploi\PloiSshConnection;
use App\Services\SshConnectionFactory;
use RuntimeException;

/**
 * Reads the dump file off the Ploi server (placed there by DumpDatabaseHandler),
 * streams it to the dply target server, and restores it into the database
 * owned by the migrated site. dply's site provisioning has already created
 * a database on the target; we restore into that.
 *
 * Streaming approach: SSH to Ploi → cat the dump → base64-encode in memory →
 * SSH-write to dply tmp → mysql < tmp on dply. For large dumps this loads the
 * dump into the orchestrator's RAM; future enhancement is direct SSH→SSH pipe
 * via stream forwarding. For the v1 (typical 10–500MB sites) the in-memory
 * approach is acceptable.
 */
class RestoreDatabaseHandler extends SshDependentHandler
{
    public function __construct(protected SshConnectionFactory $factory) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_RESTORE_DB;
    }

    protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void {
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('restore_database requires a target_site_id.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }

        // Lookup the dump from the prior dump_database step.
        $dumpStep = ImportMigrationStep::query()
            ->where('import_site_migration_id', $child->id)
            ->where('step_key', ImportMigrationStep::KEY_DUMP_DB)
            ->first();
        if ($dumpStep === null) {
            throw new RuntimeException('restore_database needs dump_database to have run.');
        }
        if ($dumpStep->status === ImportMigrationStep::STATUS_SKIPPED) {
            $step->status = ImportMigrationStep::STATUS_SKIPPED;
            $step->result_data = ['reason' => 'dump_was_skipped'];
            $step->save();

            return;
        }

        $dumpPath = $dumpStep->result_data['dump_path'] ?? null;
        $dbName = $dumpStep->result_data['database'] ?? null;
        if (! is_string($dumpPath) || ! is_string($dbName)) {
            throw new RuntimeException('dump_database result_data is malformed.');
        }

        // Pull the dump off Ploi, push to dply, restore.
        $ploiSsh = PloiSshConnection::forMigration($migration);
        $base64 = $ploiSsh->exec('base64 -w0 '.escapeshellarg($dumpPath));
        if (trim($base64) === '') {
            throw new RuntimeException('Failed to read dump from Ploi (empty content).');
        }
        $dump = base64_decode($base64, strict: true);
        if ($dump === false) {
            throw new RuntimeException('Failed to decode dump from Ploi.');
        }

        $dplyShell = $this->factory->forServer($target);
        $dplyDumpPath = sprintf('/tmp/dply-restore-%s-%d.sql', mb_substr($migration->id, -10), $child->source_site_id);
        $dplyShell->putFile($dplyDumpPath, $dump);

        // Restore. Site's database belongs to the dply server's MySQL; the site's slug
        // is the conventional database name for dply-managed sites. dply's mysql_root
        // credentials live in /root/.my.cnf on the target server (dply convention).
        $dplyDbName = $this->dplyDatabaseNameFor($site);
        $restore = sprintf(
            'mysql --defaults-extra-file=/root/.my.cnf %s < %s 2>&1 && rm -f %s',
            escapeshellarg($dplyDbName),
            escapeshellarg($dplyDumpPath),
            escapeshellarg($dplyDumpPath),
        );
        $output = $dplyShell->exec($restore, timeoutSeconds: 1800);

        // Optional cleanup on Ploi side — only AFTER successful restore to allow re-run.
        // Skip if the restore failed (orchestrator marks step failed on throw above).
        try {
            $ploiSsh->exec('rm -f '.escapeshellarg($dumpPath));
        } catch (\Throwable) {
            // best-effort; don't fail the step over cleanup
        }

        $step->result_data = [
            'source_database' => $dbName,
            'target_database' => $dplyDbName,
            'bytes' => strlen($dump),
            'restore_output_excerpt' => mb_substr($output, 0, 500),
        ];
        $step->save();
    }

    protected function dplyDatabaseNameFor(Site $site): string
    {
        // dply provisions a database named after the site slug; underscored for MySQL.
        return str_replace('-', '_', $site->slug);
    }
}
