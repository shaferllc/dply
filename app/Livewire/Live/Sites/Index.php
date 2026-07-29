<?php

declare(strict_types=1);

namespace App\Livewire\Live\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\Site;
use App\Services\ProductionData\ProductionApiException;
use App\Support\Sites\SiteIndexRow;
use App\Support\Sites\SiteIndexSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $sort = 'created_at';

    /** @var string ''|active|provisioning|attention */
    public string $statusFilter = '';

    public function mount(): void
    {
        $this->requireProductionConnection();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
    }

    public function refresh(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }
        $this->productionMirror()->forget($connection, 'sites.fleet.v2');
        $this->toastSuccess(__('Sites refreshed from production.'));
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

    public function render(): View
    {
        $connection = $this->requireProductionConnection();
        $statusOptions = [
            '' => __('All statuses'),
            'active' => __('Active'),
            'provisioning' => __('Provisioning'),
            'attention' => __('Needs attention'),
        ];
        $sortOptions = [
            'created_at' => __('Newest first'),
            'name' => __('Name (A–Z)'),
            'status' => __('Status'),
            'deployed' => __('Recently deployed'),
        ];

        if ($connection === null) {
            return view('livewire.live.sites.index', [
                'connection' => new \App\Models\ProductionDataConnection(['base_url' => '']),
                'rows' => collect(),
                'summary' => SiteIndexSummary::fromRows(collect()),
                'hasSitesInScope' => false,
                'statusOptions' => $statusOptions,
                'sortOptions' => $sortOptions,
                'error' => null,
            ]);
        }

        $error = null;
        $apiRows = [];

        try {
            $apiRows = $this->productionMirror()->remember(
                $connection,
                'sites.fleet.v2',
                fn ($client) => $client->sites(),
            );
        } catch (ProductionApiException $e) {
            if ($e->isUnauthorized()) {
                $this->handleProductionApiError($e);
            } else {
                $error = $e->getMessage();
            }
        }

        $remoteBaseUrl = (string) $connection->base_url;

        /** @var Collection<int, SiteIndexRow> $allRows */
        $allRows = collect($apiRows)->map(
            fn (array $row): SiteIndexRow => SiteIndexRow::fromProductionApi($row, $remoteBaseUrl)
        );
        $hasSitesInScope = $allRows->isNotEmpty();
        $rows = $this->filterRows($allRows);

        return view('livewire.live.sites.index', [
            'connection' => $connection,
            'rows' => $rows,
            'summary' => SiteIndexSummary::fromRows($allRows),
            'hasSitesInScope' => $hasSitesInScope,
            'statusOptions' => $statusOptions,
            'sortOptions' => $sortOptions,
            'error' => $error,
        ]);
    }

    /**
     * @param  Collection<int, SiteIndexRow>  $rows
     * @return Collection<int, SiteIndexRow>
     */
    protected function filterRows(Collection $rows): Collection
    {
        $term = mb_strtolower(trim($this->search));
        if ($term !== '') {
            $rows = $rows->filter(function (SiteIndexRow $row) use ($term): bool {
                return str_contains(mb_strtolower($row->name), $term)
                    || str_contains(mb_strtolower($row->serverName), $term)
                    || str_contains(mb_strtolower((string) $row->primaryHostname), $term)
                    || str_contains(mb_strtolower($row->id), $term);
            });
        }

        $activeStatuses = array_merge(Site::webserverActiveStatuses(), [
            Site::STATUS_DOCKER_ACTIVE,
            Site::STATUS_KUBERNETES_ACTIVE,
            Site::STATUS_FUNCTIONS_ACTIVE,
            Site::STATUS_CONTAINER_ACTIVE,
            Site::STATUS_EDGE_ACTIVE,
            Site::STATUS_CUSTOM_ACTIVE,
        ]);
        $provisioningStatuses = [
            Site::STATUS_PENDING,
            Site::STATUS_CONTAINER_PROVISIONING,
            Site::STATUS_EDGE_PROVISIONING,
            Site::STATUS_SCAFFOLDING,
        ];
        $attentionStatuses = [
            Site::STATUS_ERROR,
            Site::STATUS_CONTAINER_FAILED,
            Site::STATUS_EDGE_FAILED,
            Site::STATUS_SCAFFOLD_FAILED,
        ];

        if ($this->statusFilter === 'active') {
            $rows = $rows->filter(fn (SiteIndexRow $r): bool => in_array($r->status, $activeStatuses, true));
        } elseif ($this->statusFilter === 'provisioning') {
            $rows = $rows->filter(fn (SiteIndexRow $r): bool => in_array($r->status, $provisioningStatuses, true));
        } elseif ($this->statusFilter === 'attention') {
            $rows = $rows->filter(fn (SiteIndexRow $r): bool => in_array($r->status, $attentionStatuses, true));
        }

        $rows = match ($this->sort) {
            'name' => $rows->sortBy(fn (SiteIndexRow $r) => mb_strtolower($r->name), SORT_NATURAL),
            'status' => $rows->sortBy([
                fn (SiteIndexRow $r) => $r->status,
                fn (SiteIndexRow $r) => mb_strtolower($r->name),
            ]),
            'deployed' => $rows->sortByDesc(fn (SiteIndexRow $r) => $r->lastDeployAt?->timestamp ?? 0),
            default => $rows->sortByDesc(fn (SiteIndexRow $r) => $r->createdAt?->timestamp ?? 0),
        };

        return $rows->values();
    }
}
