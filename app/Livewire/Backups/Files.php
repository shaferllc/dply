<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Livewire\Backups\Concerns\EditsBackupSchedules;
use App\Livewire\Backups\Concerns\RunsBackupSchedules;
use App\Livewire\Backups\Concerns\SummarisesBackupRuns;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Livewire\Concerns\StagesBackupDownloads;
use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\SiteFileBackupExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Files tab: every site dply can archive, the schedules protecting them,
 * their run history, and a one-click archive to the browser.
 *
 * Owns its type end-to-end alongside Databases and Snapshots
 * (docs/adr/backups-as-a-product.md, decision 1) — which is why the schedules
 * live on this page rather than only in each server's workspace.
 */
#[Layout('layouts.app')]
class Files extends Component
{
    use DispatchesToastNotifications;
    use EditsBackupSchedules;
    use QueuesQuickDownloads;
    use RunsBackupSchedules;
    use StagesBackupDownloads;
    use SummarisesBackupRuns;
    use WithPagination;

    /** Free-text over site, server and error text. */
    #[Url(as: 'q', except: '')]
    public string $runSearch = '';

    /** '' = every status. */
    #[Url(as: 'status', except: '')]
    public string $runStatus = '';

    public function updatedRunSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRunStatus(): void
    {
        $this->resetPage();
    }

    public function clearRunFilters(): void
    {
        $this->runSearch = '';
        $this->runStatus = '';
        $this->resetPage();
    }

    public function hasRunFilters(): bool
    {
        return $this->runSearch !== '' || $this->runStatus !== '';
    }

    /**
     * Remove one archive and its stored file.
     *
     * There is no restore counterpart: unpacking a tar over a live document root
     * is a different and far riskier operation than importing a SQL dump, and
     * inventing one here would be a promise the engine cannot keep.
     */
    public function deleteArchive(string $backupId, SiteFileBackupExporter $exporter): void
    {
        $org = auth()->user()?->currentOrganization();
        if (! $org instanceof Organization) {
            return;
        }

        $serverIds = $org->servers()->pluck('id');
        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->whereIn('server_id', $serverIds))
            ->with('site')
            ->first();

        if (! $backup instanceof SiteFileBackup) {
            $this->toastError(__('That archive is no longer available.'));

            return;
        }

        $this->authorize('update', $backup->site);

        try {
            $exporter->deleteArtifact($backup);
        } catch (\Throwable $e) {
            // The row still goes — a stuck artifact must not leave an
            // undeletable entry in the history forever.
            $backup->delete();
            $this->toastError(__('Removed the record, but the stored file could not be deleted: :error', ['error' => $e->getMessage()]));

            return;
        }

        $backup->delete();
        $this->toastSuccess(__('Archive deleted.'));
    }

    public function queueFullBackup(string $siteId): void
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $serverIds = $org->servers()->pluck('id');
        $site = Site::query()
            ->whereIn('server_id', $serverIds)
            ->whereKey($siteId)
            ->firstOrFail();

        $this->authorize('update', $site);

        if (! $site->supportsSshFileArchive()) {
            $this->toastError(__('Full file backup is only available for SSH-ready VM sites.'));

            return;
        }

        $backup = SiteFileBackup::query()->create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        dispatch(new ExportSiteFileBackupJob($backup->id));

        $this->toastSuccess(__('Full backup queued. Refresh shortly to download the archive when it completes.'));
    }

    /**
     * Resolve + authorize a site-file backup for the Hetzner staging download
     * flow. Org-scoped to the user's current organization.
     */
    protected function resolveDownloadableBackup(string $type, string $backupId): ?Model
    {
        if ($type !== 'site_files') {
            return null;
        }

        $org = auth()->user()->currentOrganization();
        if (! $org) {
            return null;
        }

        $serverIds = $org->servers()->pluck('id');

        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->whereIn('server_id', $serverIds))
            ->with('site.server')
            ->first();

        if ($backup === null) {
            return null;
        }

        $this->authorize('update', $backup->site);

        return $backup;
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org instanceof Organization) {
            abort(403, 'Select an organization first.');
        }

        if (! Feature::for($org)->active('workspace.backups')) {
            return view('livewire.backups.files', ['featureActive' => false]);
        }

        $this->authorize('viewAny', Site::class);

        $serverIds = $org->servers()->pluck('id');

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'workspace.runbooks'])
            ->orderBy('name')
            ->get();

        $siteIds = $sites->pluck('id');

        $schedules = BackupSchedule::query()
            ->where('target_type', BackupSchedule::TARGET_SITE_FILES)
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'backupConfiguration'])
            ->orderByDesc('is_active')
            ->orderByDesc('last_run_at')
            ->get();

        // The view renders one row per site with its schedule folded in, so
        // schedules that no longer point at a live site have to be surfaced
        // separately or they would silently vanish from the tab.
        $schedulesByTarget = $schedules->groupBy('target_id');
        $orphanSchedules = $schedules
            ->reject(fn (BackupSchedule $schedule) => $siteIds->contains($schedule->target_id))
            ->values();
        $scheduledSiteIds = $schedules->where('is_active', true)->pluck('target_id')->unique();

        /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, SiteFileBackup>> $recentBackups */
        $recentBackups = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->groupBy(fn (SiteFileBackup $backup) => (string) $backup->site_id)
            ->map(fn ($group) => $group->take(5));

        $runs = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->with('site.server')
            ->when($this->runStatus !== '', fn ($q) => $q->where('status', $this->runStatus))
            ->when($this->runSearch !== '', function ($q) {
                $term = '%'.str_replace('%', '\\%', trim($this->runSearch)).'%';

                $q->where(function ($inner) use ($term) {
                    $inner->whereHas('site', fn ($s) => $s->where('name', 'like', $term))
                        ->orWhereHas('site.server', fn ($sv) => $sv->where('name', 'like', $term))
                        ->orWhere('error_message', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'runs');

        // Newest run per site, so a row can show that its last attempt failed —
        // inferred from history rather than probed, per the no-SSH-in-render rule.
        $lastRuns = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get(['site_id', 'status', 'error_message', 'created_at'])
            ->unique('site_id')
            ->keyBy('site_id');

        $storageBytes = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteFileBackup::STATUS_COMPLETED)
            ->sum('bytes');

        // Coverage is measured against sites that CAN be archived. An Edge or
        // serverless site has no filesystem to tar, so counting it as
        // unprotected would manufacture a gap nobody can close — the same
        // capability-aware rule the Overview's gaps band follows.
        $archivable = $sites->filter->supportsSshFileArchive();
        $protected = $archivable->filter(fn (Site $site) => $scheduledSiteIds->contains($site->id));

        $archivedSiteIds = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteFileBackup::STATUS_COMPLETED)
            ->distinct()
            ->pluck('site_id');

        return view('livewire.backups.files', [
            'featureActive' => true,
            'organization' => $org,
            // Archivable sites first: the rows with nothing actionable on them
            // should not sit between the ones an operator came here to act on.
            // sortBy is stable, so alphabetical order survives within each group.
            'sites' => $sites->sortByDesc(fn (Site $site) => $site->supportsSshFileArchive())->values(),
            'schedules' => $schedules,
            'schedulesByTarget' => $schedulesByTarget,
            'orphanSchedules' => $orphanSchedules,
            'scheduledSiteIds' => $scheduledSiteIds,
            'nextRuns' => $this->nextRuns($schedules),
            'trends' => $this->recentSizes(
                SiteFileBackup::query()->whereIn('site_id', $siteIds),
                'site_id',
            ),
            'activity' => $this->dailyActivity(
                SiteFileBackup::query()->whereIn('site_id', $siteIds),
            ),
            'recentBackups' => $recentBackups,
            'runs' => $runs,
            'lastRuns' => $lastRuns,
            'metrics' => [
                'sites' => $sites->count(),
                'archivable' => $archivable->count(),
                'unarchivable' => $sites->count() - $archivable->count(),
                'protected' => $protected->count(),
                'archivedSites' => $archivedSiteIds->count(),
                'storage' => Number::fileSize((int) $storageBytes),
                'coverage' => $archivable->count() > 0
                    ? (int) round($protected->count() / $archivable->count() * 100)
                    : 0,
            ],
            'storageDestinations' => $org->backupConfigurations()->orderBy('name')->get(['id', 'name', 'provider']),
            'providerLabels' => collect(BackupConfiguration::providers())
                ->mapWithKeys(fn (string $provider) => [$provider => BackupConfiguration::labelForProvider($provider)]),
        ]);
    }
}
