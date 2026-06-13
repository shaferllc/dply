<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\UserSshKey;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\SshKeyLabelTemplate;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSshKeyProfile
{


    protected function loadReviewDateInputs(): void
    {
        $this->reviewDates = [];
        $this->server->loadMissing('authorizedKeys');
        foreach ($this->server->authorizedKeys as $ak) {
            $this->reviewDates[$ak->id] = $ak->review_after?->format('Y-m-d') ?? '';
        }
    }

    protected function hydrateAdvancedFromServer(): void
    {
        $m = $this->server->meta ?? [];
        $this->advanced_disable_sync = (bool) data_get($m, config('server_ssh_keys.meta_disable_sync_key'));
        $this->advanced_health_check = (bool) data_get($m, config('server_ssh_keys.meta_health_check_key'));
        $this->advanced_label_template = (string) data_get($m, config('server_ssh_keys.meta_label_template_key'), '');
    }

    /**
     * @return list<string>
     */
    protected function baselineSystemUsers(): array
    {
        $u = (string) $this->server->ssh_user;
        if ($u === '') {
            return [];
        }

        return [$u];
    }

    public function updatedProfileKeyId(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $key = UserSshKey::query()
            ->where('user_id', Auth::id())
            ->whereKey($value)
            ->first();

        if ($key) {
            $this->applyLabelTemplate($key->name, (string) $this->new_target_linux_user);
            $this->new_auth_key = $key->public_key;
        }
    }

    protected function applyLabelTemplate(string $name, string $linuxUser): void
    {
        $tpl = SshKeyLabelTemplate::resolveTemplate($this->server);
        $this->new_auth_name = SshKeyLabelTemplate::apply($tpl, $name, $linuxUser, $this->server);
    }

    public function clearProfileSelection(): void
    {
        $this->profile_key_id = null;
    }

    public function generateNewAuthorizedKeyPair(): void
    {
        $this->authorize('update', $this->server);

        try {
            [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if (! UserSshKey::publicKeyLooksValid($public)) {
            $this->toastError(__('Generated key was invalid. Try again or generate a key locally with ssh-keygen.'));

            return;
        }

        $this->profile_key_id = null;

        if (trim($this->new_auth_name) === '') {
            $this->new_auth_name = __('Generated key');
        }

        $this->new_auth_key = $public;

        $this->dispatch(
            'dply-ssh-keypair-generated',
            privateKey: $private,
            publicKey: $public,
        );

        $this->toastSuccess(__('A new key pair was generated. Copy your private key from the dialog, then use “Add SSH key” and “Sync authorized_keys”.'));
    }

    #[On('personal-ssh-key-created')]
    public function refreshProfileKeysAfterCreate(): void
    {
        $this->toastSuccess(__('SSH key saved. Select it below to attach it to this server, then sync authorized_keys.'));
    }

    public function loadSystemUsers(ServerPasswdUserLister $lister): void
    {
        $this->authorize('update', $this->server);

        try {
            $names = $lister->listUsernames($this->server->fresh());
            $merged = array_values(array_unique([...$this->baselineSystemUsers(), ...$names]));
            sort($merged);
            $this->system_users = $merged;
            $this->toastSuccess(__('Loaded system users from the server.'));
        } catch (\Throwable $e) {
            $this->toastError($this->friendlyWorkspaceError($e, __('Dply could not connect to the server to load system users.')));
        }
    }
}
