<?php

namespace App\Livewire\Backups;

use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Livewire\Concerns\StagesBackupDownloads;
use App\Models\BackupConfiguration;
use App\Models\Site;
use App\Modules\Backups\Models\SiteFileBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Files extends Component
{
    use DispatchesToastNotifications;
    use QueuesQuickDownloads;
    use StagesBackupDownloads;

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
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $this->authorize('viewAny', Site::class);

        $serverIds = $org->servers()->pluck('id');
        $user = auth()->user();

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'workspace.runbooks'])
            ->orderBy('name')
            ->get();

        $siteIds = $sites->pluck('id');

        /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, SiteFileBackup>> $recentBackups */
        $recentBackups = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->groupBy(fn (SiteFileBackup $b) => (string) $b->site_id)
            ->map(fn ($group) => $group->take(5));

        $storageDestinations = $org
            ? $org->backupConfigurations()->orderBy('name')->get(['id', 'name', 'provider'])
            : collect();

        return view('livewire.backups.files', [
            'organization' => $org,
            'sites' => $sites,
            'recentBackups' => $recentBackups,
            'storageDestinations' => $storageDestinations,
            'providerLabels' => collect(BackupConfiguration::providers())
                ->mapWithKeys(fn (string $provider) => [$provider => BackupConfiguration::labelForProvider($provider)]),
        ]);
    }
}
