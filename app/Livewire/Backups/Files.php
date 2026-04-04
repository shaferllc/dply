<?php

namespace App\Livewire\Backups;

use App\Jobs\ExportSiteFileBackupJob;
use App\Models\BackupConfiguration;
use App\Models\Site;
use App\Models\SiteFileBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class Files extends Component
{
    public ?string $flash_success = null;

    public ?string $flash_error = null;

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
            $this->flash_error = __('Full file backup is only available for SSH-ready VM sites.');

            return;
        }

        $backup = SiteFileBackup::query()->create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        dispatch(new ExportSiteFileBackupJob($backup->id));

        $this->flash_success = __('Full backup queued. Refresh shortly to download the archive when it completes.');
    }

    public function downloadSiteFileBackup(string $backupId): StreamedResponse|Response|null
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $serverIds = $org->servers()->pluck('id');

        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->whereIn('server_id', $serverIds))
            ->firstOrFail();

        $this->authorize('update', $backup->site);

        if ($backup->status !== SiteFileBackup::STATUS_COMPLETED || empty($backup->disk_path)) {
            $this->flash_error = __('Backup is not ready yet.');

            return null;
        }

        if (! Storage::disk('local')->exists($backup->disk_path)) {
            $this->flash_error = __('Backup file is missing from storage.');

            return null;
        }

        $slug = $backup->site?->slug;
        $name = 'site-files-'.(($slug !== null && $slug !== '') ? $slug : 'site').'-'.$backup->id.'.tar.gz';

        return Storage::disk('local')->download($backup->disk_path, $name);
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

        $storageDestinations = $user->backupConfigurations()
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);

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
