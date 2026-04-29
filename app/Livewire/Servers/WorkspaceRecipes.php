<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Script;
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
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use StreamsRemoteSshLivewire;

    public string $new_recipe_name = '';

    public string $new_recipe_script = '';

    public string $import_script_id = '';

    public ?string $editing_recipe_id = null;

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

        if ($this->editing_recipe_id) {
            ServerRecipe::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_recipe_id)
                ->firstOrFail()
                ->update([
                    'name' => $this->new_recipe_name,
                    'script' => $this->new_recipe_script,
                ]);

            $this->toastSuccess('Saved command updated.');
        } else {
            ServerRecipe::query()->create([
                'server_id' => $this->server->id,
                'user_id' => auth()->id(),
                'name' => $this->new_recipe_name,
                'script' => $this->new_recipe_script,
            ]);

            $this->toastSuccess('Saved command added.');
        }

        $this->resetRecipeEditor();
    }

    public function editRecipe(string $id): void
    {
        $this->authorize('update', $this->server);

        $recipe = ServerRecipe::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $this->editing_recipe_id = (string) $recipe->id;
        $this->new_recipe_name = $recipe->name;
        $this->new_recipe_script = $recipe->script;
    }

    public function cancelEditingRecipe(): void
    {
        $this->resetRecipeEditor();
    }

    public function deleteRecipe(string $id): void
    {
        $this->authorize('update', $this->server);
        ServerRecipe::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        if ($this->editing_recipe_id === $id) {
            $this->resetRecipeEditor();
        }
        $this->toastSuccess('Saved command removed.');
    }

    public function importOrganizationScript(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'import_script_id' => 'required|string',
        ]);

        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            abort(403, __('Select an organization first.'));
        }

        $script = Script::query()
            ->where('organization_id', $organization->id)
            ->whereKey($this->import_script_id)
            ->firstOrFail();

        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'name' => $script->name,
            'script' => $script->content,
        ]);

        $this->import_script_id = '';
        $this->toastSuccess('Organization script copied to this server.');
    }

    public function useRecipeAsDeployCommand(string $id): void
    {
        $this->authorize('update', $this->server);

        $recipe = ServerRecipe::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $this->server->update([
            'deploy_command' => trim($recipe->script) ?: null,
        ]);

        $this->toastSuccess('Saved command copied to deploy.');
    }

    public function runRecipe(string $id): void
    {
        $this->authorize('update', $this->server);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->command_error = 'Deployers cannot run server saved commands or arbitrary shell commands.';

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
                __('Saved command').': '.$recipe->name,
                $this->server->ssh_user.'@'.$this->server->ip_address.'  '.$remoteCmd
            );
            $this->command_output = $ssh->execWithCallback(
                $remoteCmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                900
            );
            $this->toastSuccess('Saved command ran. See command output below if shown.');
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    protected function resetRecipeEditor(): void
    {
        $this->editing_recipe_id = null;
        $this->new_recipe_name = '';
        $this->new_recipe_script = '';
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['recipes']);
        $organization = auth()->user()?->currentOrganization();

        return view('livewire.servers.workspace-recipes', [
            'organizationScripts' => $organization
                ? Script::query()
                    ->where('organization_id', $organization->id)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
