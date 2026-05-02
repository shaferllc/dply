<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDeploy extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use StreamsRemoteSshLivewire;

    public string $command = '';

    public string $deploy_command = '';

    public ?string $command_output = null;

    public ?string $command_error = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->deploy_command = $server->deploy_command ?? '';
    }

    public function runCommand(): void
    {
        $this->authorize('view', $this->server);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->command_error = 'Deployers cannot run arbitrary shell commands on servers.';

            return;
        }
        $this->validate(['command' => 'required|string|max:1000']);
        $this->command_output = null;
        $this->command_error = null;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('SSH exec'),
                $this->server->ssh_user.'@'.$this->server->ip_address.'  '.$this->command
            );
            $ssh = new SshConnection($this->server);
            $this->command_output = $ssh->execWithCallback(
                $this->command,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                120
            );
            $this->command = '';
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function deploy(): void
    {
        $this->authorize('view', $this->server);
        $cmd = $this->server->deploy_command;
        if (empty(trim((string) $cmd))) {
            $this->toastError('Set a deploy command first. Use "Edit deploy command" below.');

            return;
        }
        $this->command_output = null;
        $this->command_error = null;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Deploy command'),
                $this->server->ssh_user.'@'.$this->server->ip_address.'  '.$cmd
            );
            $ssh = new SshConnection($this->server);
            $this->command_output = $ssh->execWithCallback(
                $cmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                900
            );
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function updateDeployCommand(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['deploy_command' => 'nullable|string|max:2000']);
        $this->server->update(['deploy_command' => trim($this->deploy_command) ?: null]);
        $this->toastSuccess('Deploy command updated.');
    }

    public function applyDeployTemplate(string $key): void
    {
        $this->authorize('update', $this->server);
        $templates = config('deploy_templates.templates', []);
        $template = $templates[$key] ?? null;
        if ($template && ! empty($template['command'])) {
            $this->deploy_command = $template['command'];
            $this->server->update(['deploy_command' => $template['command']]);
            $this->toastSuccess('Deploy template applied. Edit below if needed, then save.');
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-deploy', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
