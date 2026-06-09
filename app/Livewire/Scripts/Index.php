<?php

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\Script;
use App\Models\Server;
use App\Services\Scripts\ApplyOrganizationScriptToServer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use DispatchesToastNotifications;
    use RequiresFeature;

    protected string $requiredFeature = 'surface.scripts';

    use WithPagination;

    public string $search = '';

    public bool $showApplyModal = false;

    public ?string $applyScriptId = null;

    public string $applyServerId = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Script::class);
    }

    public function openApplyModal(string $scriptId): void
    {
        $script = Script::query()->findOrFail($scriptId);
        $this->authorize('view', $script);

        $this->applyScriptId = $scriptId;
        $this->applyServerId = '';
        $this->showApplyModal = true;
        $this->dispatch('open-modal', 'apply-script-to-server');
    }

    public function closeApplyModal(): void
    {
        $this->showApplyModal = false;
        $this->applyScriptId = null;
        $this->applyServerId = '';
        $this->dispatch('close-modal', 'apply-script-to-server');
    }

    public function confirmApplyToServer(ApplyOrganizationScriptToServer $applyScript): void
    {
        $this->validate([
            'applyServerId' => 'required|string',
        ]);

        $org = Auth::user()->currentOrganization();
        if ($org === null) {
            abort(403, __('Select an organization first.'));
        }

        $script = Script::query()
            ->where('organization_id', $org->id)
            ->findOrFail($this->applyScriptId);

        $this->authorize('view', $script);

        $server = Server::query()
            ->where('organization_id', $org->id)
            ->findOrFail($this->applyServerId);

        $this->authorize('update', $server);

        $recipe = $applyScript->apply($script, $server, Auth::user(), $org);
        $this->closeApplyModal();

        $this->toastSuccess(__('Saved command “:name” is ready on :server.', [
            'name' => $recipe->name,
            'server' => $server->name,
        ]));
    }

    public function render(): View
    {
        $org = Auth::user()->currentOrganization();
        if (! $org) {
            abort(403, __('Select an organization first.'));
        }

        $query = Script::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('updated_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $vmServers = Server::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server): bool => $server->isVmHost())
            ->values();

        return view('livewire.scripts.index', [
            'scripts' => $query->paginate(15),
            'organization' => $org,
            'vmServers' => $vmServers,
        ]);
    }
}
