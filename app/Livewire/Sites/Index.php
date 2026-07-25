<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use App\Support\Sites\SiteIndexRow;
use App\Support\Sites\SiteIndexSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public string $sort = 'created_at';

    /** @var string ''|active|provisioning|attention */
    public string $statusFilter = '';

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
    }

    /**
     * Sites scoped to the current org (and team, when one is active),
     * before any search/filter/sort is applied. Returns null when there's
     * no current organization so the caller can short-circuit.
     */
    protected function baseQuery(): ?Builder
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            return null;
        }

        $serversQuery = $org->servers();
        $team = auth()->user()->currentTeam();
        if ($team) {
            $serversQuery->where('team_id', $team->id);
        }
        $serverIds = $serversQuery->pluck('id');

        return Site::query()->whereIn('server_id', $serverIds);
    }

    protected function applyFilters(Builder $query): Builder
    {
        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhereHas('domains', fn (Builder $d) => $d->where('hostname', 'like', $like))
                    ->orWhereHas('server', fn (Builder $s) => $s->where('name', 'like', $like));
            });
        }

        if ($this->statusFilter === 'active') {
            $query->whereIn('status', array_merge(Site::webserverActiveStatuses(), [
                Site::STATUS_DOCKER_ACTIVE,
                Site::STATUS_KUBERNETES_ACTIVE,
                Site::STATUS_FUNCTIONS_ACTIVE,
                Site::STATUS_CONTAINER_ACTIVE,
                Site::STATUS_EDGE_ACTIVE,
                Site::STATUS_CUSTOM_ACTIVE,
            ]));
        } elseif ($this->statusFilter === 'provisioning') {
            $query->whereIn('status', [
                Site::STATUS_PENDING,
                Site::STATUS_CONTAINER_PROVISIONING,
                Site::STATUS_EDGE_PROVISIONING,
                Site::STATUS_SCAFFOLDING,
            ]);
        } elseif ($this->statusFilter === 'attention') {
            $query->whereIn('status', [
                Site::STATUS_ERROR,
                Site::STATUS_CONTAINER_FAILED,
                Site::STATUS_EDGE_FAILED,
                Site::STATUS_SCAFFOLD_FAILED,
            ]);
        }

        return match ($this->sort) {
            'name' => $query->orderBy('name'),
            'status' => $query->orderBy('status')->orderBy('name'),
            'deployed' => $query->orderByDesc('last_deploy_at')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $this->authorize('viewAny', Site::class);

        $base = $this->baseQuery();
        $hasSitesInScope = $base !== null && (clone $base)->exists();

        $sites = $base
            ? $this->applyFilters(clone $base)
                ->with(['server', 'domains', 'workspace'])
                ->get()
            : collect();

        $summarySource = $base
            ? (clone $base)->with(['server', 'domains', 'workspace'])->get()
            : collect();

        /** @var Collection<int, SiteIndexRow> $rows */
        $rows = $sites->map(fn (Site $site): SiteIndexRow => SiteIndexRow::fromSite($site));
        /** @var Collection<int, SiteIndexRow> $summaryRows */
        $summaryRows = $summarySource->map(fn (Site $site): SiteIndexRow => SiteIndexRow::fromSite($site));

        return view('livewire.sites.index', [
            'rows' => $rows,
            'organization' => $org,
            'hasSitesInScope' => $hasSitesInScope,
            'summary' => SiteIndexSummary::fromRows($summaryRows),
            'statusOptions' => [
                '' => __('All statuses'),
                'active' => __('Active'),
                'provisioning' => __('Provisioning'),
                'attention' => __('Needs attention'),
            ],
            'sortOptions' => [
                'created_at' => __('Newest first'),
                'name' => __('Name (A–Z)'),
                'status' => __('Status'),
                'deployed' => __('Recently deployed'),
            ],
        ]);
    }
}
