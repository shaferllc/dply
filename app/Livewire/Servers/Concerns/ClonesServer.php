<?php

namespace App\Livewire\Servers\Concerns;

use App\Actions\Servers\CloneServerOnDigitalOcean;
use App\Enums\ServerProvider;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

/**
 * Clone-server affordance shared by the Manage and Configuration workspaces.
 *
 * Snapshots a DigitalOcean droplet and provisions a new server from the
 * snapshot via {@see CloneServerOnDigitalOcean}. The host component must expose
 * a public `$server` property plus the usual Livewire helpers (authorize,
 * validate, dispatch, redirect) and the toast helpers from the workspace
 * concern (toastError / toastSuccess).
 *
 * @property Server $server
 */
trait ClonesServer
{
    /**
     * Clone-server modal state. `clone_open` is the modal-show toggle (driven by
     * Alpine via dispatch('open-modal', 'clone-server-modal')), and clone_name
     * is the editable target name. Region + size stay locked to the source's
     * values for v1; the operator can resize on DO after the clone lands.
     */
    public bool $clone_open = false;

    public string $clone_name = '';

    /**
     * Eligibility gate for the Clone server button. Mirrors the assertCloneable
     * checks on the action so the UI hides / disables the affordance instead of
     * relying on a post-click toast.
     */
    public function canCloneServer(): bool
    {
        if ($this->server->provider !== ServerProvider::DigitalOcean) {
            return false;
        }
        $hostKind = (string) (($this->server->meta ?? [])['host_kind'] ?? Server::HOST_KIND_VM);
        if ($hostKind !== Server::HOST_KIND_VM) {
            return false;
        }
        if (! $this->server->providerCredential || $this->server->providerCredential->provider !== 'digitalocean') {
            return false;
        }
        if ($this->server->provider_id === null || $this->server->provider_id === '') {
            return false;
        }

        return $this->server->status === Server::STATUS_READY;
    }

    public function openCloneServerModal(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->canCloneServer()) {
            $this->toastError(__('This server is not currently cloneable.'));

            return;
        }

        $this->clone_name = $this->server->name.' (clone)';
        $this->clone_open = true;
        $this->dispatch('open-modal', 'clone-server-modal');
    }

    public function cancelCloneServer(): void
    {
        $this->clone_open = false;
        $this->dispatch('close-modal', 'clone-server-modal');
    }

    public function confirmCloneServer(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->canCloneServer()) {
            $this->toastError(__('This server is not currently cloneable.'));

            return;
        }

        $this->validate([
            'clone_name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user = auth()->user();
        $org = $user?->currentOrganization();
        if ($user === null || $org === null) {
            $this->toastError(__('No active organization context.'));

            return;
        }

        try {
            $clone = app(CloneServerOnDigitalOcean::class)->handle(
                actor: $user,
                org: $org,
                source: $this->server,
                overrides: ['name' => $this->clone_name],
            );
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->all();
            $this->toastError($messages[0] ?? __('Clone failed.'));

            return;
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->clone_open = false;
        $this->dispatch('close-modal', 'clone-server-modal');
        $this->toastSuccess(__('Clone queued. Track progress on the new server\'s page.'));
        $this->redirect(route('servers.manage', ['server' => $clone, 'section' => 'overview']), navigate: true);
    }
}
