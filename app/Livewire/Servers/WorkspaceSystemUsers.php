<?php

namespace App\Livewire\Servers;

use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSystemUsers extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $new_username = '';

    public bool $new_sudo = false;

    public string $remove_username = '';

    public string $remove_confirm = '';

    /** @var list<array{username: string, site_count: int}> */
    public array $remote_rows = [];

    public ?string $list_error = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    /**
     * SSH-probes the server's `/etc/passwd`, augments with per-user site counts,
     * and stores the list for the table + dropdowns. Errors land on
     * `$list_error` so the page can show why the probe failed without throwing.
     */
    public function loadUsers(ServerPasswdUserLister $lister, ServerSystemUserService $service): void
    {
        $this->authorize('update', $this->server);
        $this->list_error = null;

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->list_error = __('The server must be ready with SSH before loading system users.');

            return;
        }

        try {
            $this->remote_rows = $service->listPasswdUsersWithSiteCounts($this->server->fresh(), $lister);
        } catch (\Throwable $e) {
            $this->list_error = $e->getMessage();
            $this->remote_rows = [];
        }
    }

    public function openCreateModal(): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();
        $this->new_username = '';
        $this->new_sudo = false;
        $this->dispatch('open-modal', 'server-system-user-create-modal');
    }

    public function closeCreateModal(): void
    {
        $this->dispatch('close-modal', 'server-system-user-create-modal');
    }

    public function queueCreate(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));

            return;
        }

        $this->validate([
            'new_username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'new_sudo' => ['boolean'],
        ]);

        CreateServerSystemUserJob::dispatch(
            $this->server->id,
            $this->new_username,
            $this->new_sudo,
            auth()->id(),
        );

        $this->closeCreateModal();
        $this->toastSuccess(__('System user creation queued. Refresh the list shortly to see the new user.'));
    }

    public function openRemoveModal(string $username): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();

        $allowed = collect($this->remote_rows)->pluck('username')->filter()->all();
        if (! in_array($username, $allowed, true)) {
            $this->toastError(__('Reload the user list before removing.'));

            return;
        }

        $this->remove_username = $username;
        $this->remove_confirm = '';
        $this->dispatch('open-modal', 'server-system-user-remove-modal');
    }

    public function closeRemoveModal(): void
    {
        $this->dispatch('close-modal', 'server-system-user-remove-modal');
    }

    public function queueRemove(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));
            $this->closeRemoveModal();

            return;
        }

        $allowed = collect($this->remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'remove_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
            'remove_confirm' => ['required', 'same:remove_username'],
        ]);

        DeleteServerSystemUserJob::dispatch($this->server->id, $this->remove_username);

        $this->closeRemoveModal();
        $this->toastSuccess(__('User removal queued. Refresh shortly to confirm.'));
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-system-users');
    }
}
