<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Imports\SourceDriverFactory;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replays Ploi daemons onto the dply Site as SiteProcess type=worker rows.
 * dply's systemd unit generator picks these up and writes the unit files
 * on the next deploy. Commands referencing Ploi-shaped paths (/home/ploi/)
 * are flagged in result_data so the Manual Review panel can warn the user.
 */
class RecreateDaemonsHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_RECREATE_DAEMONS;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('recreate_daemons requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('recreate_daemons requires a target_site_id.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        $site = Site::find($child->target_site_id);
        if ($migration === null || $site === null) {
            throw new RuntimeException('Missing dependencies for recreate_daemons.');
        }
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = app(SourceDriverFactory::class)->for($credential);
        $daemons = $driver->listDaemons($migration->source_server_id, $child->source_site_id);

        $createdRows = 0;
        $warnings = [];
        DB::transaction(function () use ($daemons, $site, &$createdRows, &$warnings): void {
            SiteProcess::query()
                ->where('site_id', $site->id)
                ->where('type', SiteProcess::TYPE_WORKER)
                ->where('name', 'like', 'imported:%')
                ->delete();

            foreach ($daemons as $d) {
                if ($d['command'] === '') {
                    continue;
                }
                $name = $d['name'] ?? ('worker-'.$d['id']);
                $command = $d['command'];
                if ($command !== '' && str_contains($command, '/home/ploi/')) {
                    $warnings[] = sprintf('Daemon "%s" references /home/ploi/ — may need path rewrite.', $name);
                }
                SiteProcess::create([
                    'site_id' => $site->id,
                    'type' => SiteProcess::TYPE_WORKER,
                    'name' => 'imported:'.$name,
                    'command' => $command,
                    'scale' => max(1, $d['processes'] ?? 1),
                    'working_directory' => $d['directory'] ?? null,
                    'user' => $d['user'] ?? null,
                    'is_active' => true,
                ]);
                $createdRows++;
            }
        });

        $step->result_data = [
            'workers_created' => $createdRows,
            'warnings' => $warnings,
        ];
        $step->save();
    }
}
