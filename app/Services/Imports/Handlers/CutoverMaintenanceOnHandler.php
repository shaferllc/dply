<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Services\Imports\SourceDriverFactory;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Cutover step #1: enable maintenance mode on the Ploi site so further writes
 * are blocked while we run the final DB delta + DNS swap. Bounds the write-
 * loss window to the cutover duration (Q8 β model).
 *
 * Also transitions the parent ImportSiteMigration to CUTOVER_IN_PROGRESS and
 * stamps cutover_started_at.
 */
class CutoverMaintenanceOnHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('cutover_maintenance_on requires a site-scoped step.');
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
        $driver->enableSiteMaintenance($migration->source_server_id, $child->source_site_id);

        $child->status = ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS;
        $child->cutover_started_at ??= Carbon::now();
        $child->save();

        $migration->status = ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS;
        $migration->save();
    }
}
