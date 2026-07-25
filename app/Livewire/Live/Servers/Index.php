<?php

declare(strict_types=1);

namespace App\Livewire\Live\Servers;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Services\ProductionData\ProductionApiException;
use App\Support\Servers\ServerIndexRow;
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
        $this->productionMirror()->forget($connection, 'servers.fleet');
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
            // Cache key versioned so a deployed fleet-card API isn't masked by a
            // prior thin-list cache entry (legacy keys: id/name/status/ip only).
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
