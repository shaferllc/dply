<?php

namespace App\Livewire\Projects;

use App\Models\WorkspaceLabel;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceView;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public string $search = '';

    public string $labelFilter = '';

    public string $roleFilter = '';

    public string $savedViewName = '';

    public function createProject(): void
    {
        $this->authorize('create', Workspace::class);

        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
        ]);

        $org->workspaces()->create([
            'user_id' => $user->id,
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
        ]);

        $this->reset('name', 'description');
        session()->flash('success', __('Project created.'));
    }

    public function saveView(): void
    {
        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            return;
        }

        $this->validate([
            'savedViewName' => 'required|string|max:120',
        ]);

        WorkspaceView::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => $this->savedViewName,
            'filters' => [
                'search' => $this->search,
                'label' => $this->labelFilter,
                'role' => $this->roleFilter,
            ],
        ]);

        $this->savedViewName = '';
        session()->flash('success', __('Saved view created.'));
    }

    public function applySavedView(string $viewId): void
    {
        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            return;
        }

        $view = WorkspaceView::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->findOrFail($viewId);

        $filters = $view->filters ?? [];
        $this->search = (string) ($filters['search'] ?? '');
        $this->labelFilter = (string) ($filters['label'] ?? '');
        $this->roleFilter = (string) ($filters['role'] ?? '');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'labelFilter', 'roleFilter');
    }

    public function render(): View
    {
        $user = auth()->user();
        $org = $user->currentOrganization();
        $workspaces = collect();
        $labels = collect();
        $views = collect();

        if ($org) {
            $query = $org->workspaces()
                ->withCount(['servers', 'sites'])
                ->with(['labels', 'members.user']);

            if (! $org->hasAdminAccess($user)) {
                $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
            }

            if ($this->search !== '') {
                $query->where(function ($q): void {
                    $term = '%'.$this->search.'%';
                    $q->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            }

            if ($this->labelFilter !== '') {
                $query->whereHas('labels', fn ($q) => $q->whereKey($this->labelFilter));
            }

            if ($this->roleFilter !== '') {
                $query->whereHas('members', function ($q) use ($user): void {
                    $q->where('user_id', $user->id)
                        ->where('role', $this->roleFilter);
                });
            }

            $workspaces = $query->orderBy('name')->get();
            $labels = WorkspaceLabel::query()
                ->where('organization_id', $org->id)
                ->orderBy('name')
                ->get();
            $views = WorkspaceView::query()
                ->where('organization_id', $org->id)
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get();
        }

        return view('livewire.projects.index', [
            'workspaces' => $workspaces,
            'hasOrganization' => $org !== null,
            'labels' => $labels,
            'views' => $views,
            'workspaceRoles' => WorkspaceMember::roles(),
        ]);
    }
}
