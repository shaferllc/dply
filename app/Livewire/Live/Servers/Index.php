<?php

declare(strict_types=1);

namespace App\Livewire\Live\Servers;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\ConfirmsProductionWrites;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Models\Site;
use App\Services\ProductionData\ProductionApiException;
use App\Support\Servers\ServerIndexRow;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use ConfirmsProductionWrites;
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $sort = 'created_at';

    public string $statusFilter = '';

    public string $tagFilter = '';

    /** @var string list|grid */
    public string $viewMode = 'list';

    public function mount(): void
    {
        $this->requireProductionConnection();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
        $this->tagFilter = '';
        $this->viewMode = 'list';
    }

    public function refresh(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }
        $this->productionMirror()->forget($connection, 'servers.fleet.v3');
        $this->toastSuccess(__('Servers refreshed from production.'));
    }

    public function disconnect(): void
    {
        $connection = $this->productionConnection;
        if ($connection === null) {
            return;
        }

        $this->productionMirror()->disconnect($connection);
        $this->toastWarning(__('Disconnected from production.'));
        $this->redirect(route('live.connect'), navigate: true);
    }

    /**
     * Same entry point as the local fleet — queues production deploys via API
     * after the PRODUCTION write confirm (not an external link).
     */
    public function openServerDeploy(string $serverId): void
    {
        $siteIds = $this->deployableSiteIdsForServer($serverId);
        if ($siteIds === []) {
            $this->toastError(__('No deployable sites on this host.'));

            return;
        }

        $this->runProductionWrite(
            'performProductionDeploys',
            [$siteIds],
            __('Deploy on production'),
            trans_choice(
                'Queue a deploy for :n production site.|Queue deploys for :n production sites.',
                count($siteIds),
                ['n' => count($siteIds)],
            ).' '.__('Type PRODUCTION to allow writes for this session.'),
        );
    }

    /**
     * Same entry point as the local fleet Sync servers button.
     */
    public function deploySyncedSites(string $siteId): void
    {
        $siteIds = $this->syncPeerSiteIds($siteId);
        if ($siteIds === []) {
            $this->toastError(__('No linked sites to sync-deploy.'));

            return;
        }

        $this->runProductionWrite(
            'performProductionDeploys',
            [$siteIds],
            __('Sync deploy on production'),
            trans_choice(
                'Queue a sync deploy for :n production site.|Queue sync deploys for :n production sites.',
                count($siteIds),
                ['n' => count($siteIds)],
            ).' '.__('Type PRODUCTION to allow writes for this session.'),
        );
    }

    /**
     * @param  list<string>  $siteIds
     */
    public function performProductionDeploys(array $siteIds): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        $siteIds = array_values(array_unique(array_filter($siteIds, fn ($id): bool => is_string($id) && $id !== '')));
        if ($siteIds === []) {
            $this->toastError(__('No sites to deploy.'));

            return;
        }

        try {
            $queued = 0;
            $mirror = $this->productionMirror();
            $mirror->withClient($connection, function ($client) use ($siteIds, $connection, $mirror, &$queued): void {
                foreach ($siteIds as $siteId) {
                    $client->deploy($siteId);
                    $queued++;
                    $mirror->forget($connection, 'site:'.$siteId.':deployments');
                    $mirror->forget($connection, 'site:'.$siteId);
                }
            });

            $this->toastSuccess(trans_choice(
                'Queued :n production deploy.|Queued :n production deploys.',
                $queued,
                ['n' => $queued],
            ));
        } catch (ProductionApiException $e) {
            $this->handleProductionApiError($e);
        }
    }

    public function render(): View
    {
        $connection = $this->requireProductionConnection();
        $statusOptions = [
            '' => __('All statuses'),
            Server::STATUS_PENDING => __('Pending'),
            Server::STATUS_PROVISIONING => __('Provisioning'),
            Server::STATUS_READY => __('Ready'),
            Server::STATUS_ERROR => __('Error'),
            Server::STATUS_DISCONNECTED => __('Disconnected'),
        ];
        $sortOptions = collect(config('user_preferences.server_sort_options', [
            'created_at' => 'Creation date',
            'name' => 'Name',
            'status' => 'Status',
        ]))->map(fn (string $label): string => __($label))->all();

        if ($connection === null) {
            return view('livewire.live.servers.index', [
                'connection' => new ProductionDataConnection(['base_url' => '']),
                'groupedRows' => collect(),
                'summary' => ServerIndexRow::summarize(collect()),
                'hasServersInScope' => false,
                'statusOptions' => $statusOptions,
                'sortOptions' => $sortOptions,
                'tagOptions' => [],
                'error' => null,
                'legacyApi' => false,
                'writesUnlocked' => false,
            ]);
        }

        $error = null;
        $apiRows = [];

        try {
            $apiRows = $this->productionMirror()->remember(
                $connection,
                'servers.fleet.v3',
                fn ($client) => $client->servers(),
            );
        } catch (ProductionApiException $e) {
            if ($e->isUnauthorized()) {
                $this->handleProductionApiError($e);
            } else {
                $error = $e->getMessage();
            }
        }

        $apiRows = ServerIndexRow::enrichDeploySyncMeta($apiRows);

        $legacyApi = ServerIndexRow::isLegacyApiPayload($apiRows);
        $groupLabel = $connection->remote_organization_name;

        /** @var Collection<int, ServerIndexRow> $allRows */
        $allRows = collect($apiRows)->map(
            fn (array $row): ServerIndexRow => ServerIndexRow::fromProductionApi(
                $row,
                $connection->base_url,
                $groupLabel,
            )
        );
        $tagOptions = $allRows->flatMap(fn (ServerIndexRow $r) => $r->tags)->unique()->sort()->values()->all();
        $rows = $this->filterRows($allRows);

        return view('livewire.live.servers.index', [
            'connection' => $connection,
            'groupedRows' => ServerIndexRow::group($rows),
            'summary' => ServerIndexRow::summarize($allRows),
            'hasServersInScope' => $allRows->isNotEmpty(),
            'statusOptions' => $statusOptions,
            'sortOptions' => $sortOptions,
            'tagOptions' => $tagOptions,
            'error' => $error,
            'legacyApi' => $legacyApi,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function deployableSiteIdsForServer(string $serverId): array
    {
        foreach ($this->cachedApiServerRows() as $row) {
            if ((string) ($row['id'] ?? '') !== $serverId) {
                continue;
            }

            $ids = [];
            foreach ($row['sites'] ?? [] as $site) {
                if (! is_array($site)) {
                    continue;
                }
                $id = isset($site['id']) ? (string) $site['id'] : '';
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return Site::query()
            ->where('server_id', $serverId)
            ->whereNotNull('git_repository_url')
            ->where('git_repository_url', '!=', '')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function syncPeerSiteIds(string $siteId): array
    {
        $local = Site::query()->with('server')->find($siteId);
        if ($local !== null) {
            return SiteSyncPeers::forSite($local)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all();
        }

        $anchorRepo = '';
        $idsByRepo = [];
        foreach ($this->cachedApiServerRows() as $row) {
            foreach ($row['sites'] ?? [] as $site) {
                if (! is_array($site)) {
                    continue;
                }
                $id = isset($site['id']) ? (string) $site['id'] : '';
                if ($id === '') {
                    continue;
                }
                $repo = SiteSyncPeers::canonicalRepo((string) ($site['git_repository_url'] ?? ''));
                if ($repo === '') {
                    continue;
                }
                $idsByRepo[$repo][] = $id;
                if ($id === $siteId) {
                    $anchorRepo = $repo;
                }
            }
        }

        if ($anchorRepo !== '' && isset($idsByRepo[$anchorRepo])) {
            return array_values(array_unique($idsByRepo[$anchorRepo]));
        }

        return [$siteId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function cachedApiServerRows(): array
    {
        $connection = $this->productionConnection;
        if ($connection === null) {
            return [];
        }

        try {
            $rows = $this->productionMirror()->remember(
                $connection,
                'servers.fleet.v3',
                fn ($client) => $client->servers(),
            );

            return ServerIndexRow::enrichDeploySyncMeta(is_array($rows) ? $rows : []);
        } catch (ProductionApiException) {
            return [];
        }
    }

    /**
     * @param  Collection<int, ServerIndexRow>  $rows
     * @return Collection<int, ServerIndexRow>
     */
    protected function filterRows(Collection $rows): Collection
    {
        $term = mb_strtolower(trim($this->search));
        if ($term !== '') {
            $rows = $rows->filter(function (ServerIndexRow $row) use ($term): bool {
                return str_contains(mb_strtolower($row->name), $term)
                    || str_contains(mb_strtolower((string) $row->ipAddress), $term)
                    || str_contains(mb_strtolower($row->provider), $term)
                    || str_contains(mb_strtolower($row->providerLabel), $term);
            });
        }

        if ($this->statusFilter !== '') {
            $rows = $rows->filter(fn (ServerIndexRow $r): bool => $r->status === $this->statusFilter);
        }

        $tag = trim($this->tagFilter);
        if ($tag !== '') {
            $rows = $rows->filter(fn (ServerIndexRow $r): bool => in_array($tag, $r->tags, true));
        }

        $rows = match ($this->sort) {
            'name' => $rows->sortBy(fn (ServerIndexRow $r) => mb_strtolower($r->name), SORT_NATURAL),
            'status' => $rows->sortBy([
                fn (ServerIndexRow $r) => $r->status,
                fn (ServerIndexRow $r) => mb_strtolower($r->name),
            ]),
            default => $rows->sortByDesc(fn (ServerIndexRow $r) => $r->createdAt?->timestamp ?? 0),
        };

        return $rows->values();
    }
}
