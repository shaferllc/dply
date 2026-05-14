<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replays Ploi crons for the site onto the dply server. ServerCronJob rows
 * carry both server_id and site_id, so we can scope per-site crons even
 * though the underlying crontab is server-level. Sync to the live server's
 * crontab is handled by dply's existing cron sync machinery whenever a
 * ServerCronJob is created (is_synced=false signals "not yet on disk").
 *
 * Idempotent via per-site replace — the handler wipes existing
 * imported-from-this-migration crons before re-creating to keep the dply
 * side as the single source of truth.
 */
class RecreateCronsHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_RECREATE_CRONS;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('recreate_crons requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('recreate_crons requires a target_site_id.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        $site = Site::find($child->target_site_id);
        if ($migration === null || $site === null) {
            throw new RuntimeException('Missing dependencies for recreate_crons.');
        }
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = app(\App\Services\Imports\SourceDriverFactory::class)->for($credential);
        $crons = $driver->listSiteCrons($migration->source_server_id, $child->source_site_id);

        $created = DB::transaction(function () use ($crons, $site, $child): int {
            // Replace strategy: clear any previously imported-from-this-source crons.
            ServerCronJob::query()
                ->where('site_id', $site->id)
                ->where('description', 'like', 'imported:%')
                ->delete();

            $count = 0;
            foreach ($crons as $cron) {
                if ($cron['command'] === '' || $cron['schedule'] === '') {
                    continue;
                }
                ServerCronJob::create([
                    'server_id' => $site->server_id,
                    'site_id' => $site->id,
                    'cron_expression' => $cron['schedule'],
                    'command' => $cron['command'],
                    'user' => $cron['user'] ?? 'root',
                    'enabled' => true,
                    'description' => 'imported:'.$child->source.':'.$cron['id'],
                    'is_synced' => false, // dply's cron sync writes it to disk
                ]);
                $count++;
            }

            return $count;
        });

        $step->result_data = ['crons_created' => $created];
        $step->save();
    }
}
