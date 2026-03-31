<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDatabases extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $new_db_name = '';

    public string $new_db_engine = 'mysql';

    public string $new_db_username = '';

    public string $new_db_password = '';

    public string $new_db_host = '127.0.0.1';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function createDatabase(ServerDatabaseProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_db_name' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_engine' => 'required|in:mysql,postgres',
            'new_db_username' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_password' => 'required|string|max:200',
            'new_db_host' => 'required|string|max:255',
        ]);

        $this->flash_success = null;
        $this->flash_error = null;

        try {
            $db = ServerDatabase::query()->create([
                'server_id' => $this->server->id,
                'name' => $this->new_db_name,
                'engine' => $this->new_db_engine,
                'username' => $this->new_db_username,
                'password' => $this->new_db_password,
                'host' => $this->new_db_host,
            ]);
            $out = $provisioner->createOnServer($db);
            $this->flash_success = 'Database record created and provision attempted on server. SSH output (if any): '.Str::limit($out, 500);
            $this->new_db_name = '';
            $this->new_db_username = '';
            $this->new_db_password = '';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function deleteDatabase(int $id): void
    {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);
        $db->delete();
        $this->flash_success = 'Database entry removed from Dply (remote DB not dropped).';
        $this->flash_error = null;
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['serverDatabases']);

        return view('livewire.servers.workspace-databases', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
