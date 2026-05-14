<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Imports\Ploi\PloiImportDriver;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Detects the Laravel scheduler cron (Ploi commonly installs
 * `php artisan schedule:run` as a per-minute cron) and recreates it as a
 * dply SiteProcess type=scheduler. For non-Laravel sites this is a no-op.
 *
 * Per Q16, scheduler runs as a SiteProcess (dply-native shape) rather than
 * a literal cron entry, because that gives the user one place to manage
 * the scheduler with consistent restart/scale semantics.
 */
class RecreateSchedulerHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_RECREATE_SCHEDULER;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('recreate_scheduler requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('recreate_scheduler requires a target_site_id.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        $site = Site::find($child->target_site_id);
        if ($migration === null || $site === null) {
            throw new RuntimeException('Missing dependencies for recreate_scheduler.');
        }

        if ($child->site_type !== 'laravel') {
            $step->result_data = ['scheduler_created' => false, 'reason' => 'not_laravel'];
            $step->save();

            return;
        }

        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = PloiImportDriver::for($credential);
        $crons = $driver->listSiteCrons($migration->source_server_id, $child->source_site_id);

        $hasScheduler = false;
        foreach ($crons as $cron) {
            if (str_contains($cron['command'], 'schedule:run')) {
                $hasScheduler = true;
                break;
            }
        }

        if (! $hasScheduler) {
            $step->result_data = ['scheduler_created' => false, 'reason' => 'no_scheduler_cron_on_source'];
            $step->save();

            return;
        }

        DB::transaction(function () use ($site): void {
            SiteProcess::query()
                ->where('site_id', $site->id)
                ->where('type', SiteProcess::TYPE_SCHEDULER)
                ->where('name', 'like', 'imported:%')
                ->delete();

            SiteProcess::create([
                'site_id' => $site->id,
                'type' => SiteProcess::TYPE_SCHEDULER,
                'name' => 'imported:scheduler',
                'command' => 'php artisan schedule:work',
                'scale' => 1,
                'is_active' => true,
            ]);
        });

        $step->result_data = ['scheduler_created' => true];
        $step->save();
    }
}
