<?php

namespace App\Livewire\Cloud;

use App\Models\Cloud\CloudCluster;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire component for listing dply Cloud clusters.
 */
class ClusterIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get the current organization.
     */
    #[Computed]
    public function organization(): \App\Models\Organization
    {
        return Auth::user()->currentOrganization;
    }

    /**
     * Get filtered clusters.
     */
    #[Computed]
    public function clusters(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = CloudCluster::query()
            ->where('organization_id', $this->organization->id)
            ->withCount('cloudApps');

        if ($this->search) {
            $query->where(function ($q): void {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('region', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query->orderByDesc('created_at')->paginate(10);
    }

    /**
     * Get status counts for filter tabs.
     */
    #[Computed]
    public function statusCounts(): array
    {
        $baseQuery = CloudCluster::where('organization_id', $this->organization->id);

        return [
            'all' => (clone $baseQuery)->count(),
            'ready' => (clone $baseQuery)->where('status', CloudCluster::STATUS_READY)->count(),
            'pending' => (clone $baseQuery)->whereIn('status', [CloudCluster::STATUS_PENDING, CloudCluster::STATUS_PROVISIONING])->count(),
            'error' => (clone $baseQuery)->where('status', CloudCluster::STATUS_ERROR)->count(),
        ];
    }

    public function render()
    {
        return view('livewire.cloud.cluster-index');
    }
}
