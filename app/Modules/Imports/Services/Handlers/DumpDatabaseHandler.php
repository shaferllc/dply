<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Modules\Imports\Services\SourceDriverFactory;
use App\Modules\Imports\Services\SourceSshConnectionFactory;
use App\Modules\Imports\Services\StepHandler;
use RuntimeException;

/**
 * SSH into the Ploi server using the ephemeral key and dump the site's
 * primary database via mysqldump --single-transaction --routines --triggers
 * into /tmp/dply-migrate-{run}-{site}.sql. The companion RestoreDatabaseHandler
 * later transfers that file to the dply server and restores it.
 *
 * dply's user `ploi` on a Ploi server has passwordless sudo plus access to
 * the MySQL credentials in /home/ploi/.my.cnf; we rely on that to invoke
 * mysqldump without surfacing a password to the orchestrator.
 *
 * Multi-database sites: this handler dumps the FIRST database returned by
 * driver->listSiteDatabases. The migration-wide design (Q8) is one DB per
 * site; multi-DB sites are flagged with a warning in result_data and only
 * the primary is migrated.
 */
class DumpDatabaseHandler implements StepHandler
{
    public function __construct(protected SourceSshConnectionFactory $sourceFactory) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_DUMP_DB;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('dump_database requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null) {
            throw new RuntimeException('Child migration missing.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing.');
        }
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = app(SourceDriverFactory::class)->for($credential);
        $dbs = $driver->listSiteDatabases($migration->source_server_id, $child->source_site_id);

        if ($dbs === []) {
            // No DB on this site (legitimate for some PHP sites) — skip cleanly.
            $step->status = ImportMigrationStep::STATUS_SKIPPED;
            $step->result_data = ['reason' => 'no_database_on_source_site'];
            $step->save();

            return;
        }

        $primary = $dbs[0];
        $warnings = [];
        if (count($dbs) > 1) {
            $extra = array_map(fn ($d) => $d['name'], array_slice($dbs, 1));
            $warnings[] = 'Site has multiple databases; only '.$primary['name'].' will be migrated. Others: '.implode(', ', $extra);
        }

        $ssh = $this->sourceFactory->forMigration($migration);
        $dumpPath = sprintf('/tmp/dply-migrate-%s-%d.sql', mb_substr($migration->id, -10), $child->source_site_id);
        $dbName = $primary['name'];

        // --single-transaction gives InnoDB-safe consistent snapshot. --routines + --triggers
        // capture stored procedures + triggers. Defaults file at /home/ploi/.my.cnf supplies creds.
        $cmd = sprintf(
            'mysqldump --defaults-extra-file=/home/ploi/.my.cnf --single-transaction --routines --triggers %s > %s 2>&1',
            escapeshellarg($dbName),
            escapeshellarg($dumpPath),
        );
        $output = $ssh->exec($cmd, timeoutSeconds: 1800);

        // Probe file size to verify the dump worked.
        $size = trim($ssh->exec('stat -c %s '.escapeshellarg($dumpPath).' 2>/dev/null || echo 0'));
        $bytes = (int) $size;
        if ($bytes === 0) {
            throw new RuntimeException('mysqldump produced empty file; mysqldump output: '.mb_substr($output, 0, 1000));
        }

        $step->result_data = [
            'database' => $dbName,
            'dump_path' => $dumpPath,
            'bytes' => $bytes,
            'warnings' => $warnings,
        ];
        $step->save();
    }
}
