<?php

namespace App\Livewire\Profile;

use App\Models\UserSshKey;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class PersonalSshKeyModal extends Component
{
    public string $modalName = 'personal-ssh-key-modal';

    public ?string $source = null;

    public string $name = '';

    public string $public_key = '';

    public bool $provision_on_new_servers = true;

    public function mount(?string $source = null, string $modalName = 'personal-ssh-key-modal'): void
    {
        $this->source = $source;
        $this->modalName = $modalName;
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', $this->modalName);
    }

    public function generateKeyPair(): void
    {
        $this->authorize('create', UserSshKey::class);
        $this->resetErrorBag();

        try {
            [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }

        if (! UserSshKey::publicKeyLooksValid($public)) {
            $this->dispatch('notify', message: __('Generated key was invalid. Try again or generate a key locally with ssh-keygen.'), type: 'error');

            return;
        }

        if (trim($this->name) === '') {
            $this->name = __('Generated key');
        }

        $this->public_key = $public;

        $this->dispatch(
            'dply-ssh-profile-keypair-generated',
            privateKey: $private,
            publicKey: $public,
        );
    }

    public function save(): void
    {
        $this->authorize('create', UserSshKey::class);

        $this->resetErrorBag();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:8000'],
            'provision_on_new_servers' => ['boolean'],
        ], [], [
            'name' => __('name'),
            'public_key' => __('public key'),
        ]);

        if (! UserSshKey::publicKeyLooksValid($this->public_key)) {
            $this->addError('public_key', __('Paste a valid SSH public key (for example ssh-ed25519 or ssh-rsa).'));

            return;
        }

        Auth::user()->sshKeys()->create([
            'name' => trim($this->name),
            'public_key' => trim($this->public_key),
            'provision_on_new_servers' => $this->provision_on_new_servers,
        ]);

        $message = $this->source === 'servers.create'
            ? __('SSH key saved. You can continue creating your BYO server now.')
            : __('SSH key saved.');

        $this->resetForm();
        $this->dispatch('personal-ssh-key-created');
        $this->dispatch('notify', message: $message);
        $this->dispatch('close-modal', $this->modalName);
    }

    public function render(): View
    {
        return view('livewire.profile.personal-ssh-key-modal');
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'public_key']);
        $this->provision_on_new_servers = true;
        $this->resetErrorBag();
    }
}
