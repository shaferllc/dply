<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\CreateServerImageJob;
use App\Jobs\RestoreSiteSnapshotJob;
use App\Jobs\TakeSiteSnapshotJob;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesRedisSnapshots;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Models\ServerImage;
use App\Models\Snapshot;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Unified Snapshots hub for a server — full-state point-in-time captures, distinct
 * from the logical {@see WorkspaceBackups} surface (SQL dumps + file archives).
 *
 * Four tabs, each a blade partial:
 *   - images    → full-disk server/VM images via the cloud provider API (DO/Hetzner)
 *   - cache     → redis-family RDB snapshots ({@see ManagesRedisSnapshots})
 *   - databases → per-site DB snapshots ({@see Snapshot} / SnapshotService)
 *   - volumes   → block-storage snapshots (capability-gated; Phase 3, no backend yet)
 *
 * Replaces the former Redis-only WorkspaceRedisSnapshots component; the old
 * /redis-snapshots route now redirects here.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSnapshots extends Component
{
    use InteractsWithServerWorkspace;
    use ManagesRedisSnapshots;
    use RendersWorkspacePlaceholder;

    public const TABS = ['images', 'cache', 'databases', 'volumes'];

    /** Active tab: images | cache | databases | volumes. */
    public string $snapshots_tab = 'images';

    /** Form: name for a new server image (blank → auto-generated at create). */
    public string $new_image_name = '';

    /** Form: which site a new database snapshot targets. */
    public string $snapshot_site_id = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->authorize('view', $this->server);
        $this->snapshots_tab = $this->defaultTab();
    }

    public function setSnapshotsTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->snapshots_tab = $tab;
        }
    }

    /** Lead with the tab most relevant to this server's shape. */
    protected function defaultTab(): string
    {
        if (ServerImageProvider::supports($this->server)) {
            return 'images';
        }
        if ($this->primaryCacheService() !== null) {
            return 'cache';
        }

        return 'databases';
    }

    // ---- Server images (images tab) -------------------------------------

    public function createServerImage(): void
    {
        $this->authorize('update', $this->server);

        if (! ServerImageProvider::supports($this->server)) {
            $this->toastError(__('Image snapshots are not available on :provider.', ['provider' => $this->server->provider?->label() ?? __('this provider')]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('This server is not ready to snapshot yet.'));

            return;
        }

        $name = trim($this->new_image_name);
        if ($name === '') {
            $name = str(($this->server->name ?: 'server'))->slug()->value().'-'.now()->format('Y-m-d-His');
        }

        $image = ServerImage::query()->create([
            'server_id' => $this->server->id,
            'organization_id' => $this->server->organization_id,
            'user_id' => auth()->id(),
            'provider' => $this->server->provider?->value,
            'name' => $name,
            'status' => ServerImage::STATUS_PENDING,
        ]);

        CreateServerImageJob::dispatch($image->id);

        $this->new_image_name = '';
        $this->toastSuccess(__('Image capture queued. It appears below and completes in a few minutes.'));
    }

    public function deleteServerImage(string $imageId): void
    {
        $this->authorize('update', $this->server);

        $image = ServerImage::query()
            ->where('server_id', $this->server->id)
            ->whereKey($imageId)
            ->first();
        if ($image === null) {
            return;
        }

        // Best-effort provider-side delete for completed images; the local row
        // is removed regardless so a stale/failed capture can always be cleared.
        if ($image->status === ServerImage::STATUS_COMPLETED && filled($image->provider_image_id)) {
            try {
                app(ServerImageProvider::class)->delete($this->server, (string) $image->provider_image_id);
            } catch (\Throwable $e) {
                $this->toastError(__('Could not delete the image at the provider: :err', ['err' => $e->getMessage()]));

                return;
            }
        }

        $image->delete();
        $this->toastSuccess(__('Image deleted.'));
    }

    // ---- Site database snapshots (databases tab) ------------------------

    public function takeSiteSnapshot(): void
    {
        $this->authorize('update', $this->server);

        $site = $this->serverSites()->firstWhere('id', $this->snapshot_site_id);
        if ($site === null) {
            $this->toastError(__('Pick a site to snapshot.'));

            return;
        }

        TakeSiteSnapshotJob::dispatch($site->id, auth()->id());

        $this->toastSuccess(__('Snapshot queued for :site. It appears in History when the dump finishes.', ['site' => $site->name]));
    }

    public function restoreSiteSnapshot(string $snapshotId): void
    {
        $this->authorize('update', $this->server);

        $snapshot = $this->siteSnapshotsQuery()->whereKey((int) $snapshotId)->first();
        if ($snapshot === null) {
            return;
        }

        RestoreSiteSnapshotJob::dispatch($snapshot->id, auth()->id());

        $this->toastSuccess(__('Restore queued. This overwrites the live database when it runs.'));
    }

    public function deleteSiteSnapshot(string $snapshotId): void
    {
        $this->authorize('update', $this->server);

        $snapshot = $this->siteSnapshotsQuery()->whereKey((int) $snapshotId)->first();
        $snapshot?->delete();
        $this->toastSuccess(__('Snapshot record deleted.'));
    }

    /** @return Collection<int, \App\Models\Site> */
    protected function serverSites(): Collection
    {
        return $this->server->sites()->orderBy('name')->get();
    }

    /** Base query for this server's site DB snapshots (scoped via the server's sites). */
    protected function siteSnapshotsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Snapshot::query()
            ->whereIn('site_id', $this->server->sites()->pluck('id'));
    }

    public function render(): View
    {
        $sites = $this->serverSites();

        $siteSnapshots = $this->siteSnapshotsQuery()
            ->with(['site', 'takenByUser'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $serverImages = ServerImage::query()
            ->where('server_id', $this->server->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('livewire.servers.workspace-snapshots', array_merge(
            $this->redisSnapshotViewData(),
            [
                'imagesSupported' => ServerImageProvider::supports($this->server),
                'volumesSupported' => $this->server->provider?->supportsVolumeSnapshots() ?? false,
                'opsReady' => $this->serverOpsReady(),
                'serverImages' => $serverImages,
                'sites' => $sites,
                'siteSnapshots' => $siteSnapshots,
            ],
        ));
    }
}
