<?php

namespace App\Livewire\Settings;

use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesGitProviderTokens;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ConnectProviderModal extends Component
{
    use DispatchesToastNotifications;
    use ManagesGitProviderTokens;

    public string $modalName = 'connect-provider';

    /** When true, the PAT entry form replaces the OAuth list. */
    public bool $showPatForm = false;

    public function showPatEntry(): void
    {
        $this->showPatForm = true;
        if ($this->addingPatProvider === null) {
            $this->startAddPat('github');
        }
    }

    public function hidePatEntry(): void
    {
        $this->showPatForm = false;
        $this->cancelAddPat();
    }

    protected function afterGitProviderTokenSaved(string $provider): void
    {
        $this->toastSuccess(__('Personal access token saved. You can pick repositories from this account now.'));
        $this->showPatForm = false;
        $this->dispatch('source-control-linked');
        $this->dispatch('close-modal', $this->modalName);
    }

    public function render(): View
    {
        return view('livewire.settings.connect-provider-modal', [
            'oauthProviders' => OAuthController::getEnabledProviders(),
            'patProviders' => [
                ['id' => 'github', 'name' => 'GitHub'],
                ['id' => 'gitlab', 'name' => 'GitLab'],
                ['id' => 'bitbucket', 'name' => 'Bitbucket'],
            ],
        ]);
    }
}
