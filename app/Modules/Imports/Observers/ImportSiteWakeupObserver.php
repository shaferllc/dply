<?php

declare(strict_types=1);

namespace App\Modules\Imports\Observers;

use App\Modules\Imports\Jobs\RunMigrationStepJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportSiteMigration;
use App\Models\Site;
use App\Modules\Imports\Services\MigrationPlanner;
use Illuminate\Support\Facades\Log;

/**
 * Wakes up paused import-migration steps when a target Site transitions to a
 * traffic-ready status. Counterpart to ServerObserver::resumeWaitingImportMigrations
 * for the Server-ready transition; this one fires when ProvisionSiteJob finishes
 * setting up the imported site so handlers gated on WaitForTargetSiteException
 * can resume cleanly.
 */
class ImportSiteWakeupObserver
{
    public function updated(Site $site): void
    {
        if (! $site->wasChanged('status')) {
            return;
        }
        if (! $site->isReadyForTraffic()) {
            return;
        }

        try {
            $child = ImportSiteMigration::query()
                ->where('target_site_id', $site->id)
                ->first();

            if ($child === null) {
                return;
            }

            $next = ImportMigrationStep::query()
                ->where('import_server_migration_id', $child->import_server_migration_id)
                ->where('status', ImportMigrationStep::STATUS_PENDING)
                ->whereNotIn('step_key', MigrationPlanner::CUTOVER_STEPS)
                ->orderBy('sequence')
                ->first();

            if ($next !== null) {
                RunMigrationStepJob::dispatch($next->id);
            }
        } catch (\Throwable $e) {
            Log::warning('failed to resume import migration on site ready', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
