<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSshKeys extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $new_auth_name = '';

    public string $new_auth_key = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function addAuthorizedKey(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_auth_name' => 'required|string|max:120',
            'new_auth_key' => 'required|string|max:4000',
        ]);
        ServerAuthorizedKey::query()->create([
            'server_id' => $this->server->id,
            'name' => $this->new_auth_name,
            'public_key' => trim($this->new_auth_key),
        ]);
        $this->new_auth_name = '';
        $this->new_auth_key = '';
        $this->flash_success = 'Key stored. Click “Sync authorized_keys”.';
        $this->flash_error = null;
    }

    public function deleteAuthorizedKey(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerAuthorizedKey::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Key removed. Sync again to update server.';
        $this->flash_error = null;
    }

    public function syncAuthorizedKeys(ServerAuthorizedKeysSynchronizer $sync): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $sync->sync($this->server);
            $this->flash_success = 'authorized_keys updated. '.$out;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['authorizedKeys']);

        return view('livewire.servers.workspace-ssh-keys', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
