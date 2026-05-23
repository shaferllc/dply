<?php

namespace App\Livewire\Settings;

use App\Actions\Auth\UnlinkSocialAccount;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class SourceControl extends Component
{
    use ConfirmsActionWithModal;

    public ?string $editingId = null;

    public string $editLabel = '';

    public ?string $editingPatId = null;

    public string $editPatLabel = '';

    public ?string $addingPatProvider = null;

    public string $patLabel = '';

    public string $patToken = '';

    public string $patApiBaseUrl = '';

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

    public function startAddPat(string $provider): void
    {
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return;
        }
        $this->cancelEdit();
        $this->cancelEditPat();
        $this->addingPatProvider = $provider;
        $this->patLabel = '';
        $this->patToken = '';
        $this->patApiBaseUrl = '';
        $this->resetErrorBag(['patLabel', 'patToken', 'patApiBaseUrl']);
    }

    public function cancelAddPat(): void
    {
        $this->addingPatProvider = null;
        $this->patLabel = '';
        $this->patToken = '';
        $this->patApiBaseUrl = '';
    }

    public function savePat(): void
    {
        $provider = $this->addingPatProvider;
        if (! is_string($provider) || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return;
        }

        $this->validate([
            'patLabel' => ['nullable', 'string', 'max:255'],
            'patToken' => ['required', 'string', 'min:8', 'max:1024'],
            'patApiBaseUrl' => ['nullable', 'url', 'max:255'],
        ]);

        $token = trim($this->patToken);
        $base = $this->resolveBaseUrl($provider, $this->patApiBaseUrl);
        $profile = $this->fetchProfile($provider, $base, $token);
        if ($profile === null) {
            $this->addError('patToken', __('The :provider rejected the token. Check the value and the scopes/permissions, then try again.', ['provider' => ucfirst($provider)]));

            return;
        }

        GitProviderToken::create([
            'user_id' => auth()->id(),
            'provider' => $provider,
            'provider_id' => $profile['id'],
            'label' => $this->patLabel === '' ? null : $this->patLabel,
            'nickname' => $profile['nickname'],
            'access_token' => $token,
            'api_base_url' => trim($this->patApiBaseUrl) !== '' ? rtrim(trim($this->patApiBaseUrl), '/') : null,
            'last_validated_at' => now(),
        ]);

        $this->cancelAddPat();
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

    private function resolveBaseUrl(string $provider, string $userInput): string
    {
        $custom = trim($userInput);
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return match ($provider) {
            'github' => 'https://api.github.com',
            'gitlab' => 'https://gitlab.com',
            'bitbucket' => 'https://api.bitbucket.org',
            default => '',
        };
    }

    /**
     * Hit the provider's /user (or equivalent) endpoint to confirm the token
     * works and capture the account's stable id + handle for display.
     *
     * @return array{id: string, nickname: string}|null
     */
    private function fetchProfile(string $provider, string $base, string $token): ?array
    {
        try {
            $url = match ($provider) {
                'github' => $base.'/user',
                'gitlab' => $base.'/api/v4/user',
                'bitbucket' => $base.'/2.0/user',
                default => null,
            };
            if ($url === null) {
                return null;
            }

            $request = Http::withToken($token)->acceptJson();
            if ($provider === 'github') {
                $request = $request->withHeaders([
                    'User-Agent' => 'Dply (pat-validate)',
                    'Accept' => 'application/vnd.github+json',
                ]);
            }

            $response = $request->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = is_array($response->json()) ? $response->json() : [];

            return match ($provider) {
                'github' => [
                    'id' => isset($body['id']) ? (string) $body['id'] : '',
                    'nickname' => (string) ($body['login'] ?? $body['name'] ?? ''),
                ],
                'gitlab' => [
                    'id' => isset($body['id']) ? (string) $body['id'] : '',
                    'nickname' => (string) ($body['username'] ?? $body['name'] ?? ''),
                ],
                'bitbucket' => [
                    'id' => (string) ($body['account_id'] ?? $body['uuid'] ?? ''),
                    'nickname' => (string) ($body['username'] ?? $body['display_name'] ?? ''),
                ],
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
