<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceRecipes extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use StreamsRemoteSshLivewire;

    public string $new_recipe_name = '';

    public string $new_recipe_script = '';

    public ?string $command_output = null;

    public ?string $command_error = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function addRecipe(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_recipe_name' => 'required|string|max:160',
            'new_recipe_script' => 'required|string|max:32000',
        ]);
        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'name' => $this->new_recipe_name,
            'script' => $this->new_recipe_script,
        ]);
        $this->new_recipe_name = '';
        $this->new_recipe_script = '';
        $this->flash_success = 'Recipe saved.';
        $this->flash_error = null;
    }

    public function deleteRecipe(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerRecipe::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Recipe removed.';
        $this->flash_error = null;
    }

    public function runRecipe(int $id): void
    {
        $this->authorize('update', $this->server);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->command_error = 'Deployers cannot run server recipes or arbitrary shell commands.';

            return;
        }
        $recipe = ServerRecipe::query()->where('server_id', $this->server->id)->findOrFail($id);
        $this->command_output = null;
        $this->command_error = null;
        try {
            $ssh = new SshConnection($this->server);
            $b64 = base64_encode($recipe->script);
            $remoteCmd = 'echo '.escapeshellarg($b64).' | base64 -d | /usr/bin/env bash 2>&1';
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Recipe').': '.$recipe->name,
                $this->server->ssh_user.'@'.$this->server->ip_address.'  '.$remoteCmd
            );
            $this->command_output = $ssh->execWithCallback(
                $remoteCmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                900
            );
            $this->flash_success = 'Recipe ran. See command output below if shown.';
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['recipes']);

        return view('livewire.servers.workspace-recipes', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
