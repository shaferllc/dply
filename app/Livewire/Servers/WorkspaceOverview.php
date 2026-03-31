<?php

namespace App\Livewire\Servers;

use App\Jobs\CheckServerHealthJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceOverview extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $health_check_url = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->health_check_url = (string) ($server->meta['health_check_url'] ?? '');
    }

    public function checkHealth(): void
    {
        $this->authorize('view', $this->server);
        if ($this->server->status === Server::STATUS_READY && ! empty($this->server->ip_address)) {
            CheckServerHealthJob::dispatch($this->server);
        }
        $this->flash_success = 'Health check has been queued. Status will update shortly.';
    }

    public function saveHealthCheckUrl(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['health_check_url' => 'nullable|string|url|max:500']);
        $meta = $this->server->meta ?? [];
        $meta['health_check_url'] = trim($this->health_check_url) ?: null;
        if ($meta['health_check_url'] === null) {
            unset($meta['health_check_url']);
        }
        $this->server->update(['meta' => $meta]);
        $this->flash_success = 'Health check URL updated.';
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-overview', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
