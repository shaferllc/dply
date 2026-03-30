<?php

namespace App\Livewire\Projects;

use App\Models\Server;
use App\Models\Site;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Workspace $workspace;

    public string $editName = '';

    public string $editDescription = '';

    public ?string $serverToAttach = null;

    public ?string $siteToAttach = null;

    public function mount(Workspace $workspace): void
    {
        if ($workspace->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        $this->authorize('view', $workspace);

        $this->workspace = $workspace->load(['servers', 'sites']);
        $this->editName = $workspace->name;
        $this->editDescription = (string) ($workspace->description ?? '');
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'editName' => 'required|string|max:120',
            'editDescription' => 'nullable|string|max:2000',
        ]);

        $this->workspace->update([
            'name' => $this->editName,
            'description' => $this->editDescription !== '' ? $this->editDescription : null,
        ]);

        $this->workspace->refresh();
        $this->editName = $this->workspace->name;
        $this->editDescription = (string) ($this->workspace->description ?? '');
        session()->flash('success', __('Project updated.'));
    }

    public function attachServer(): void
    {
        $this->authorize('update', $this->workspace);

        if (! $this->serverToAttach) {
            return;
        }

        $server = Server::query()->findOrFail($this->serverToAttach);
        if ($server->organization_id !== $this->workspace->organization_id) {
            abort(403);
        }

        $this->authorize('update', $server);
        $server->update(['workspace_id' => $this->workspace->id]);
        $this->serverToAttach = null;
        $this->workspace->load(['servers', 'sites']);
        session()->flash('success', __('Server added to project.'));
    }

    public function detachServer(int $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        if ($server->workspace_id !== $this->workspace->id) {
            abort(404);
        }

        $this->authorize('update', $server);
        $server->update(['workspace_id' => null]);
        $this->workspace->load(['servers', 'sites']);
        session()->flash('success', __('Server removed from project.'));
    }

    public function attachSite(): void
    {
        $this->authorize('update', $this->workspace);

        if (! $this->siteToAttach) {
            return;
        }

        $site = Site::query()->findOrFail($this->siteToAttach);
        if ($site->organization_id !== $this->workspace->organization_id) {
            abort(403);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => $this->workspace->id]);
        $this->siteToAttach = null;
        $this->workspace->load(['servers', 'sites']);
        session()->flash('success', __('Site added to project.'));
    }

    public function detachSite(int $siteId): void
    {
        $site = Site::query()->findOrFail($siteId);
        if ($site->workspace_id !== $this->workspace->id) {
            abort(404);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => null]);
        $this->workspace->load(['servers', 'sites']);
        session()->flash('success', __('Site removed from project.'));
    }

    public function destroyWorkspace(): void
    {
        $this->authorize('delete', $this->workspace);

        $this->workspace->delete();
        session()->flash('success', __('Project deleted. Servers and sites are unchanged but no longer grouped.'));

        $this->redirect(route('projects.index'), navigate: true);
    }

    public function render(): View
    {
        $orgId = $this->workspace->organization_id;

        $availableServers = Server::query()
            ->where('organization_id', $orgId)
            ->where(function ($q): void {
                $q->whereNull('workspace_id')
                    ->orWhere('workspace_id', '!=', $this->workspace->id);
            })
            ->orderBy('name')
            ->get();

        $availableSites = Site::query()
            ->where('organization_id', $orgId)
            ->where(function ($q): void {
                $q->whereNull('workspace_id')
                    ->orWhere('workspace_id', '!=', $this->workspace->id);
            })
            ->orderBy('name')
            ->get();

        return view('livewire.projects.show', [
            'availableServers' => $availableServers,
            'availableSites' => $availableSites,
        ]);
    }
}
