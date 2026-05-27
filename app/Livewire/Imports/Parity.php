<?php

declare(strict_types=1);

namespace App\Livewire\Imports;

use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\PloiServer;
use App\Models\PloiSite;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Post-import parity dashboard. Lists every Forge / Ploi server migration
 * the org has run and compares the source-of-truth inventory (the
 * synced ForgeServer / PloiServer + their sites) against what now lives
 * in dply. Highlights drift the operator should react to:
 *
 *   - sites added to the source after migration (need follow-up import)
 *   - sites removed from the source after migration (orphaned in dply)
 *   - sites whose ImportSiteMigration aborted or failed cutover
 *   - how stale the source inventory sync is
 *
 * Differentiation hook: most importers go silent after cutover. The
 * parity view stays useful for the operator's full handoff window — the
 * source credential stays connected as a comparison oracle, not as a
 * dependency.
 */
#[Layout('layouts.app')]
class Parity extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $migrations = ImportServerMigration::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [
                ImportServerMigration::STATUS_COMPLETED,
                ImportServerMigration::STATUS_PARTIAL,
                ImportServerMigration::STATUS_ABORTED,
            ])
            ->with(['targetServer', 'siteMigrations.targetSite', 'providerCredential'])
            ->orderByDesc('completed_at')
            ->get();

        // Group migrations by source so we can resolve all source servers
        // in two queries instead of N.
        $forgeServerIds = $migrations
            ->where('source', 'forge')
            ->pluck('source_server_id')
            ->filter()
            ->unique();
        $ploiServerIds = $migrations
            ->where('source', 'ploi')
            ->pluck('source_server_id')
            ->filter()
            ->unique();

        $forgeServers = ForgeServer::query()
            ->whereIn('source_id', $forgeServerIds)
            ->with('sites')
            ->get()
            ->keyBy('source_id');
        $ploiServers = PloiServer::query()
            ->whereIn('source_id', $ploiServerIds)
            ->with('sites')
            ->get()
            ->keyBy('source_id');

        $rows = $migrations->map(fn (ImportServerMigration $m) => $this->buildRow($m, $forgeServers, $ploiServers));

        $totals = [
            'migrations' => $rows->count(),
            'drifted' => $rows->where('has_drift', true)->count(),
            'in_sync' => $rows->where('has_drift', false)->count(),
        ];

        return view('livewire.imports.parity', [
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    /**
     * @param  Collection<int, ForgeServer>  $forgeServers  keyed by source_id
     * @param  Collection<int, PloiServer>  $ploiServers   keyed by source_id
     * @return array<string, mixed>
     */
    private function buildRow(
        ImportServerMigration $migration,
        Collection $forgeServers,
        Collection $ploiServers,
    ): array {
        $sourceSnapshot = match ($migration->source) {
            'forge' => $forgeServers->get($migration->source_server_id),
            'ploi' => $ploiServers->get($migration->source_server_id),
            default => null,
        };

        $sourceSites = $this->sourceSites($sourceSnapshot);
        $migratedSiteIds = $migration->siteMigrations->pluck('source_site_id')->filter()->all();

        $addedAfterMigration = [];
        foreach ($sourceSites as $sourceSite) {
            if (! in_array($sourceSite->source_id, $migratedSiteIds, true)) {
                $addedAfterMigration[] = [
                    'source_id' => $sourceSite->source_id,
                    'domain' => $sourceSite->domain,
                    'site_type' => $sourceSite->site_type,
                ];
            }
        }

        $sourceSiteIds = $sourceSites->pluck('source_id')->all();
        $removedFromSource = [];
        $failedCutover = [];
        foreach ($migration->siteMigrations as $siteMigration) {
            if (
                $siteMigration->source_site_id !== null
                && $sourceSnapshot !== null
                && ! in_array($siteMigration->source_site_id, $sourceSiteIds, true)
            ) {
                $removedFromSource[] = [
                    'domain' => $siteMigration->domain ?? '—',
                    'target_site_id' => $siteMigration->target_site_id,
                    'target_site_name' => $siteMigration->targetSite?->name,
                ];
            }
            if (in_array($siteMigration->status, [
                ImportSiteMigration::STATUS_ABORTED,
                ImportSiteMigration::STATUS_CUTOVER_FAILED,
                ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK,
            ], true)) {
                $failedCutover[] = [
                    'domain' => $siteMigration->domain ?? '—',
                    'status' => $siteMigration->status,
                    'failure_summary' => $siteMigration->failure_summary,
                ];
            }
        }

        $migratedSiteCount = $migration->siteMigrations
            ->where('status', ImportSiteMigration::STATUS_COMPLETED)
            ->count();

        $hasDrift = $addedAfterMigration !== []
            || $removedFromSource !== []
            || $failedCutover !== [];

        return [
            'migration' => $migration,
            'source_label' => $migration->source === 'forge' ? 'Laravel Forge' : 'Ploi',
            'source_server' => $sourceSnapshot,
            'source_inventory_stale' => $sourceSnapshot?->last_synced_at?->lt(now()->subHour()),
            'source_last_synced_at' => $sourceSnapshot?->last_synced_at,
            'target_server' => $migration->targetServer,
            'source_site_count' => count($sourceSiteIds),
            'migrated_site_count' => $migratedSiteCount,
            'added_after_migration' => $addedAfterMigration,
            'removed_from_source' => $removedFromSource,
            'failed_cutover' => $failedCutover,
            'has_drift' => $hasDrift,
        ];
    }

    /**
     * @return Collection<int, ForgeSite|PloiSite>
     */
    private function sourceSites(ForgeServer|PloiServer|null $server): Collection
    {
        if ($server === null) {
            return collect();
        }

        return $server->sites
            ->filter(fn ($site) => ! $site->removed_from_source)
            ->values();
    }
}
