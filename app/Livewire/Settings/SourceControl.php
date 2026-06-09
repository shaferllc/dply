<?php

namespace App\Livewire\Settings;

use App\Actions\Auth\UnlinkSocialAccount;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\ManagesGitProviderTokens;
use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class SourceControl extends Component
{
    use ConfirmsActionWithModal;
    use ManagesGitProviderTokens;

    public ?string $editingId = null;

    public string $editLabel = '';

    public ?string $editingPatId = null;

    public string $editPatLabel = '';

    public function getProvidersProperty(): array
    {
        $enabled = OAuthController::getEnabledProviders();
        $hostFor = fn (string $id): string => match ($id) {
            'github' => 'github.com',
            'gitlab' => 'gitlab.com',
            'bitbucket' => 'bitbucket.org',
            default => '',
        };

        // Even if a provider's OAuth app isn't configured, the operator can
        // still add a PAT — surface all three core hosts when at least one
        // PAT exists for them, otherwise show only enabled OAuth providers.
        $providerIds = array_unique(array_merge(
            array_map(fn ($p) => $p['id'], $enabled),
            ['github', 'gitlab', 'bitbucket'],
        ));

        $names = [
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
        ];

        $out = [];
        foreach ($providerIds as $id) {
            $accounts = auth()->user()->socialAccounts()->where('provider', $id)->orderBy('id')->get();
            $pats = auth()->user()->gitProviderTokens()->where('provider', $id)->orderBy('id')->get();
            $oauthEnabled = collect($enabled)->contains(fn ($p) => $p['id'] === $id);

            // Skip providers with no OAuth config AND no existing PATs — they
            // shouldn't clutter the page until the operator opts in.
            if (! $oauthEnabled && $pats->isEmpty()) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'name' => $names[$id] ?? ucfirst($id),
                'accounts' => $accounts,
                'pats' => $pats,
                'oauth_enabled' => $oauthEnabled,
                'host' => $hostFor($id),
            ];
        }

        return $out;
    }

    public function startEdit(int|string $accountId): void
    {
        $account = SocialAccount::query()
            ->where('user_id', auth()->id())
            ->findOrFail($accountId);
        $this->editingId = (string) $account->getKey();
        $this->editLabel = $account->label ?? '';
        $this->cancelEditPat();
        $this->cancelAddPat();
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

    public function startEditPat(string $patId): void
    {
        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($patId);
        $this->editingPatId = $pat->getKey();
        $this->editPatLabel = (string) ($pat->label ?? '');
        $this->cancelEdit();
        $this->cancelAddPat();
    }

    public function saveEditPat(): void
    {
        $this->validate([
            'editPatLabel' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingPatId === null) {
            return;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($this->editingPatId);

        $pat->update([
            'label' => $this->editPatLabel === '' ? null : $this->editPatLabel,
        ]);

        $this->cancelEditPat();
    }

    public function cancelEditPat(): void
    {
        $this->editingPatId = null;
        $this->editPatLabel = '';
    }

    public function unlinkPat(string $patId): void
    {
        $pat = GitProviderToken::query()
            ->where('user_id', auth()->id())
            ->findOrFail($patId);

        $pat->delete();
        $this->cancelEditPat();
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
