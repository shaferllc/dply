<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportSiteMigration;
use App\Models\PloiSite;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Carbon;

/**
 * Server-level step that re-validates each child site is still eligible for
 * v1 migration (laravel/php with a usable git remote). Sites whose source
 * PloiSite has since been removed_from_source, or whose site_type has been
 * downgraded to unsupported, are flagged aborted with a clear reason — but
 * the rest of the migration proceeds. This honours the per-site abort scope
 * from Q13: one bad apple doesn't fail the whole run.
 */
class EligibilityScanHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_ELIGIBILITY_SCAN;
    }

    public function execute(ImportMigrationStep $step): void
    {
        $children = ImportSiteMigration::query()
            ->where('import_server_migration_id', $step->import_server_migration_id)
            ->where('status', ImportSiteMigration::STATUS_PENDING)
            ->get();

        $aborted = 0;
        foreach ($children as $child) {
            $reason = $this->disqualify($child);
            if ($reason === null) {
                continue;
            }

            $child->status = ImportSiteMigration::STATUS_ABORTED;
            $child->failure_summary = $reason;
            $child->save();

            // Cascade-abort the pending steps for this site so the orchestrator skips them.
            ImportMigrationStep::query()
                ->where('import_site_migration_id', $child->id)
                ->where('status', ImportMigrationStep::STATUS_PENDING)
                ->update([
                    'status' => ImportMigrationStep::STATUS_SKIPPED,
                    'finished_at' => Carbon::now(),
                ]);
            $aborted++;
        }

        $step->result_data = [
            'children_checked' => $children->count(),
            'children_aborted' => $aborted,
        ];
        $step->save();
    }

    protected function disqualify(ImportSiteMigration $child): ?string
    {
        if (! in_array($child->site_type, ['laravel', 'php'], true)) {
            return sprintf('site_type %s is not eligible for v1 migration', $child->site_type);
        }

        $snapshot = $child->source_snapshot ?? [];
        if (empty($snapshot['repository']) && empty($snapshot['repository_url'])) {
            return 'site has no git remote; cannot clone';
        }

        $latest = PloiSite::query()
            ->where('source_id', $child->source_site_id)
            ->whereHas('ploiServer', function ($q) use ($child): void {
                $parent = $child->serverMigration;
                if ($parent) {
                    $q->where('provider_credential_id', $parent->provider_credential_id);
                }
            })
            ->first();

        if ($latest === null) {
            return 'source site missing from latest inventory pull';
        }

        if ($latest->removed_from_source) {
            return 'source site was removed from Ploi after the migration was confirmed';
        }

        return null;
    }
}
