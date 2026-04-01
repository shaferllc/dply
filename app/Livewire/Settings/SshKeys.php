<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Jobs\SyncServerAuthorizedKeysJob;
use App\Models\Server;
use App\Models\UserSshKey;
use App\Services\Servers\UserSshKeyDeploymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.settings')]
class SshKeys extends Component
{
    use ConfirmsActionWithModal;

    public string $new_name = '';

    public string $new_public_key = '';

    public bool $new_provision_on_new_servers = false;

    /** @var array<int, string> */
    public array $new_server_ids = [];

    public ?int $editing_id = null;

    public string $edit_name = '';

    public string $edit_public_key = '';

    public bool $edit_provision_on_new_servers = false;

    public ?int $deploying_id = null;

    /** @var array<int, string> */
    public array $deploy_server_ids = [];

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    public ?string $setup_source = null;

    public ?string $return_to = null;

    public function mount(): void
    {
        $this->authorize('viewAny', UserSshKey::class);

        $source = request()->string('source')->toString();
        $returnTo = request()->string('return_to')->toString();

        $this->setup_source = $source !== '' ? $source : null;
        $this->return_to = $returnTo !== '' && Route::has($returnTo) ? $returnTo : null;
    }

    public function createKey(UserSshKeyDeploymentService $deployment): void
    {
        $this->authorize('create', UserSshKey::class);
        $this->resetErrorBag();

        $allowedServerIds = $this->serversForUi()->pluck('id')->all();

        $this->validate([
            'new_name' => ['required', 'string', 'max:120'],
            'new_public_key' => ['required', 'string', 'max:8000'],
            'new_provision_on_new_servers' => ['boolean'],
            'new_server_ids' => ['array'],
            'new_server_ids.*' => ['string', Rule::in($allowedServerIds)],
        ], [], [
            'new_name' => __('name'),
            'new_public_key' => __('public key'),
        ]);

        if (! UserSshKey::publicKeyLooksValid($this->new_public_key)) {
            $this->addError('new_public_key', __('Paste a valid SSH public key (for example ssh-ed25519 or ssh-rsa).'));

            return;
        }

        $user = Auth::user();
        $key = $user->sshKeys()->create([
            'name' => $this->new_name,
            'public_key' => trim($this->new_public_key),
            'provision_on_new_servers' => $this->new_provision_on_new_servers,
        ]);

        $this->new_name = '';
        $this->new_public_key = '';
        $this->new_provision_on_new_servers = false;

        $ids = array_values(array_filter(array_map('strval', $this->new_server_ids)));
        $this->new_server_ids = [];

        if ($ids !== []) {
            $result = $deployment->deployToServers($user, $key, $ids);
            if (! $result['ok']) {
                $this->flash_error = $result['message'].' '.implode(' ', $result['errors']);
                $this->flash_success = __('Key saved; fix server errors above or deploy again from the list.');

                return;
            }
        }

        $this->flash_success = $ids === []
            ? __('SSH key saved.')
            : __('SSH key saved and deployed to the selected servers.');
        $this->flash_error = null;

        if ($this->setup_source === 'servers.create') {
            $this->flash_success .= ' '.__('You can go back to create your server now.');
        }
    }

    #[On('personal-ssh-key-created')]
    public function refreshAfterPersonalSshKeyCreated(): void
    {
        $this->flash_success = __('SSH key saved.');
        $this->flash_error = null;
    }

    public function startEdit(int $id): void
    {
        $key = UserSshKey::query()->where('user_id', Auth::id())->findOrFail($id);
        $this->authorize('update', $key);
        $this->editing_id = $key->id;
        $this->edit_name = $key->name;
        $this->edit_public_key = $key->public_key;
        $this->edit_provision_on_new_servers = $key->provision_on_new_servers;
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->edit_name = '';
        $this->edit_public_key = '';
        $this->edit_provision_on_new_servers = false;
    }

    public function saveEdit(UserSshKeyDeploymentService $deployment): void
    {
        if ($this->editing_id === null) {
            return;
        }

        $key = UserSshKey::query()->where('user_id', Auth::id())->findOrFail($this->editing_id);
        $this->authorize('update', $key);

        $this->validate([
            'edit_name' => ['required', 'string', 'max:120'],
            'edit_public_key' => ['required', 'string', 'max:8000'],
            'edit_provision_on_new_servers' => ['boolean'],
        ], [], [
            'edit_name' => __('name'),
            'edit_public_key' => __('public key'),
        ]);

        if (! UserSshKey::publicKeyLooksValid($this->edit_public_key)) {
            $this->addError('edit_public_key', __('Paste a valid SSH public key (for example ssh-ed25519 or ssh-rsa).'));

            return;
        }

        $key->update([
            'name' => $this->edit_name,
            'public_key' => trim($this->edit_public_key),
            'provision_on_new_servers' => $this->edit_provision_on_new_servers,
        ]);

        $key->refresh();
        $deployment->syncLinkedServerRows($key);

        foreach ($key->serverAuthorizedKeys()->pluck('server_id')->unique() as $serverId) {
            SyncServerAuthorizedKeysJob::dispatch($serverId);
        }

        $this->cancelEdit();
        $this->flash_success = __('SSH key updated.');
        $this->flash_error = null;
    }

    public function startDeploy(int $id): void
    {
        $key = UserSshKey::query()->where('user_id', Auth::id())->findOrFail($id);
        $this->authorize('update', $key);
        $this->deploying_id = $key->id;
        $this->deploy_server_ids = [];
    }

    public function cancelDeploy(): void
    {
        $this->deploying_id = null;
        $this->deploy_server_ids = [];
    }

    public function confirmDeploy(UserSshKeyDeploymentService $deployment): void
    {
        if ($this->deploying_id === null) {
            return;
        }

        $key = UserSshKey::query()->where('user_id', Auth::id())->findOrFail($this->deploying_id);
        $this->authorize('update', $key);

        $allowedServerIds = $this->serversForUi()->pluck('id')->all();

        $this->validate([
            'deploy_server_ids' => ['required', 'array', 'min:1'],
            'deploy_server_ids.*' => ['string', Rule::in($allowedServerIds)],
        ], [], [
            'deploy_server_ids' => __('servers'),
        ]);

        $ids = array_values(array_filter(array_map('strval', $this->deploy_server_ids)));
        $result = $deployment->deployToServers(Auth::user(), $key, $ids);

        $this->cancelDeploy();

        if (! $result['ok']) {
            $this->flash_error = $result['message'].' '.implode(' ', $result['errors']);
            $this->flash_success = null;

            return;
        }

        $this->flash_success = $result['message'];
        $this->flash_error = null;
    }

    public function deleteKey(int $id): void
    {
        $key = UserSshKey::query()->where('user_id', Auth::id())->findOrFail($id);
        $this->authorize('delete', $key);

        $serverIds = $key->serverAuthorizedKeys()->pluck('server_id')->unique()->values()->all();

        $key->delete();

        foreach ($serverIds as $serverId) {
            SyncServerAuthorizedKeysJob::dispatch($serverId);
        }

        $this->flash_success = __('SSH key removed. Remaining keys are being synced on affected servers.');
        $this->flash_error = null;
    }

    /**
     * @return Collection<int, Server>
     */
    protected function serversForUi(): Collection
    {
        $org = Auth::user()->currentOrganization();
        if (! $org) {
            return new Collection;
        }

        return Server::query()
            ->where(function ($q) use ($org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(fn ($q2) => $q2->whereNull('organization_id')->where('user_id', Auth::id()));
            })
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        $keys = Auth::user()->sshKeys()->orderBy('name')->get();

        return view('livewire.settings.ssh-keys', [
            'sshKeys' => $keys,
            'servers' => $this->serversForUi(),
            'currentOrganization' => Auth::user()->currentOrganization(),
            'returnUrl' => $this->return_to ? route($this->return_to) : null,
        ]);
    }
}
