<?php

namespace App\Livewire\Settings;

use App\Actions\Auth\UnlinkSocialAccount;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\SocialAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class SourceControl extends Component
{
    use ConfirmsActionWithModal;

    public ?int $editingId = null;

    public string $editLabel = '';

    public function getProvidersProperty(): array
    {
        $enabled = OAuthController::getEnabledProviders();
        $out = [];
        foreach ($enabled as $p) {
            $id = $p['id'];
            $accounts = auth()->user()->socialAccounts()->where('provider', $id)->orderBy('id')->get();
            $out[] = [
                'id' => $id,
                'name' => $p['name'],
                'accounts' => $accounts,
                'host' => match ($id) {
                    'github' => 'github.com',
                    'gitlab' => 'gitlab.com',
                    'bitbucket' => 'bitbucket.org',
                    default => '',
                },
            ];
        }

        return $out;
    }

    public function startEdit(int $accountId): void
    {
        $account = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->findOrFail($accountId);
        $this->editingId = $accountId;
        $this->editLabel = $account->label ?? '';
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editLabel' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingId === null) {
            return;
        }

        $account = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->editingId);

        $account->update([
            'label' => $this->editLabel === '' ? null : $this->editLabel,
        ]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editLabel = '';
    }

    public function unlinkAccount(int|string $accountId): void
    {
        $user = auth()->user();
        $account = SocialAccount::query()
            ->where('user_id', $user->id)
            ->findOrFail($accountId);

        if (! UnlinkSocialAccount::allowed($user)) {
            $this->addError('unlink', UnlinkSocialAccount::denyMessage());

            return;
        }

        $account->delete();
        $this->cancelEdit();
    }

    public function repositoryCount(string $host): int
    {
        if ($host === '') {
            return 0;
        }

        return auth()->user()->gitHostRepositoryCount($host);
    }

    public function render(): View
    {
        return view('livewire.settings.source-control');
    }
}
