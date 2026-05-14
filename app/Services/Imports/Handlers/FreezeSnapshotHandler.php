<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportSiteMigration;
use App\Models\PloiSite;
use App\Services\Imports\StepHandler;
use RuntimeException;

/**
 * Per-site step that re-stamps the source_snapshot from the current PloiSite
 * onto the ImportSiteMigration. The planner already populated source_snapshot
 * at confirm time; this handler is the safety net for the (rare) case where
 * the planner ran ahead of the latest inventory pull and we want to catch any
 * drift before staging mutates anything.
 *
 * Q15 design intent: inventory refreshes never mutate in-flight migrations.
 * The snapshot frozen by THIS handler is the single source of truth for the
 * remaining staging steps; later inventory refreshes still don't touch it.
 */
class FreezeSnapshotHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_FREEZE_SNAPSHOT;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('freeze_snapshot expected a site-scoped step.');
        }

        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null) {
            throw new RuntimeException('Child migration disappeared before freeze_snapshot ran.');
        }

        $parent = $child->serverMigration;
        if ($parent === null) {
            throw new RuntimeException('Parent migration missing for child '.$child->id);
        }

        $latest = PloiSite::query()
            ->where('source_id', $child->source_site_id)
            ->whereHas('ploiServer', fn ($q) => $q->where('provider_credential_id', $parent->provider_credential_id))
            ->first();

        if ($latest === null) {
            throw new RuntimeException('Source PloiSite missing for freeze_snapshot');
        }

        $child->source_snapshot = $latest->source_snapshot ?? $child->source_snapshot ?? [];
        $child->save();

        $step->result_data = ['frozen_keys' => array_keys($child->source_snapshot ?? [])];
        $step->save();
    }
}
