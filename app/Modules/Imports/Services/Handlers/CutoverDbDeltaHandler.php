<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Imports\Services\SourceDriverFactory;
use App\Modules\Imports\Services\SourceSshConnectionFactory;
use App\Services\SshConnectionFactory;
use RuntimeException;

/**
 * Cutover step #2: re-dump the source database (now that writes are blocked
 * via maintenance mode), transfer to dply, and restore — replacing the
 * staging-time data with the final pre-cutover snapshot. The staging dump
 * was speculative; THIS dump is the authoritative cutover state.
 */
class CutoverDbDeltaHandler extends SshDependentHandler
{
    public function __construct(
        protected SshConnectionFactory $factory,
        protected SourceSshConnectionFactory $sourceFactory,
    ) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_CUTOVER_DB_DELTA;
    }

    protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void {
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('cutover_db_delta requires a target_site_id.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = app(SourceDriverFactory::class)->for($credential);
        $dbs = $driver->listSiteDatabases($migration->source_server_id, $child->source_site_id);
        if ($dbs === []) {
            $step->status = ImportMigrationStep::STATUS_SKIPPED;
            $step->result_data = ['reason' => 'no_database'];
            $step->save();

            return;
        }
        $dbName = $dbs[0]['name'];

        $ploiSsh = $this->sourceFactory->forMigration($migration);
        $deltaPath = sprintf('/tmp/dply-cutover-%s-%d.sql', mb_substr($migration->id, -10), $child->source_site_id);

        $cmd = sprintf(
            'mysqldump --defaults-extra-file=/home/ploi/.my.cnf --single-transaction --routines --triggers %s > %s 2>&1',
            escapeshellarg($dbName),
            escapeshellarg($deltaPath),
        );
        $ploiSsh->exec($cmd, timeoutSeconds: 1800);

        $b64 = trim($ploiSsh->exec('base64 -w0 '.escapeshellarg($deltaPath)));
        if ($b64 === '') {
            throw new RuntimeException('Cutover delta dump empty.');
        }
        $dump = base64_decode($b64, strict: true);
        if ($dump === false) {
            throw new RuntimeException('Cutover delta dump base64 decode failed.');
        }

        $dplyShell = $this->factory->forServer($target);
        $dplyDumpPath = sprintf('/tmp/dply-cutover-restore-%s-%d.sql', mb_substr($migration->id, -10), $child->source_site_id);
        $dplyDb = str_replace('-', '_', $site->slug);
        $dplyShell->putFile($dplyDumpPath, $dump);

        // `; rm -f` (not `&& rm -f`) so a failed restore still clears the
        // staged delta dump from /tmp; `( exit $rc )` keeps mysql's status.
        $restore = sprintf(
            'mysql --defaults-extra-file=/root/.my.cnf %s < %s 2>&1; __dply_rc=$?; rm -f %s; ( exit $__dply_rc )',
            escapeshellarg($dplyDb),
            escapeshellarg($dplyDumpPath),
            escapeshellarg($dplyDumpPath),
        );
        $dplyShell->exec($restore, timeoutSeconds: 1800);

        try {
            $ploiSsh->exec('rm -f '.escapeshellarg($deltaPath));
        } catch (\Throwable) {
            // best-effort
        }

        $step->result_data = [
            'database' => $dbName,
            'bytes' => strlen($dump),
        ];
        $step->save();
    }
}
