<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSites extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $quick_site_hostname = '';

    #[Computed]
    public function canAddSite(): bool
    {
        if (! $this->server->isReady()) {
            return false;
        }

        return Gate::forUser(auth()->user())->allows('create', Site::class);
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function startQuickSite(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canAddSite) {
            return;
        }

        $this->validate([
            'quick_site_hostname' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\.\-]+$/'],
        ]);

        $query = http_build_query([
            'hostname' => strtolower(trim($this->quick_site_hostname)),
        ]);

        $this->redirect(route('sites.create', $this->server).'?'.$query);
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['sites.domains']);

        return view('livewire.servers.workspace-sites', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
