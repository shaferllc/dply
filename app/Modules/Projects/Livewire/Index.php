<?php

namespace App\Modules\Projects\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.projects';

    use DispatchesToastNotifications;

    public string $name = '';

    public string $description = '';

    public string $search = '';

    public string $labelFilter = '';

    public string $roleFilter = '';

    public function openCreateProjectModal(): void
    {
        $this->authorize('create', Workspace::class);

        $this->name = '';
        $this->description = '';
        $this->resetValidation(['name', 'description']);
        $this->dispatch('open-modal', 'create-project-modal');
    }

    public function closeCreateProjectModal(): void
    {
        $this->name = '';
        $this->description = '';
        $this->resetValidation(['name', 'description']);
        $this->dispatch('close-modal', 'create-project-modal');
    }

    public function createProject(): void
    {
        $this->authorize('create', Workspace::class);

        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            $this->toastError(__('Select an organization first.'));

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
        $this->toastSuccess(__('Project created.'));
        $this->dispatch('close-modal', 'create-project-modal');
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
        $projectsTotal = 0;
        $serversTotal = 0;
        $sitesTotal = 0;
        $membersTotal = 0;

        if ($org) {
            $query = $org->workspaces()
                ->withCount(['servers', 'sites'])
                ->with(['labels', 'members.user']);

            if (! $org->hasAdminAccess($user)) {
                $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
            }

            // Roll up the full set the member can access, before search/label/role
            // filters narrow the visible list, so the hero stats stay stable.
            $accessible = (clone $query)->get();
            $projectsTotal = $accessible->count();
            $serversTotal = $accessible->sum('servers_count');
            $sitesTotal = $accessible->sum('sites_count');
            $membersTotal = $accessible->sum(fn ($w) => $w->members->count());

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
        }

        return view('livewire.projects.index', [
            'workspaces' => $workspaces,
            'hasOrganization' => $org !== null,
            'labels' => $labels,
            'workspaceRoles' => WorkspaceMember::roles(),
            'projectsTotal' => $projectsTotal,
            'serversTotal' => $serversTotal,
            'sitesTotal' => $sitesTotal,
            'membersTotal' => $membersTotal,
        ]);
    }
}
