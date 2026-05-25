<?php

namespace App\Livewire\Concerns;

use App\Models\GitProviderToken;
use Illuminate\Support\Facades\Http;

trait ManagesGitProviderTokens
{
    public ?string $addingPatProvider = null;

    public string $patLabel = '';

    public string $patToken = '';

    public string $patApiBaseUrl = '';

    public function startAddPat(string $provider): void
    {
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return;
        }

        if (method_exists($this, 'cancelEdit')) {
            $this->cancelEdit();
        }
        if (method_exists($this, 'cancelEditPat')) {
            $this->cancelEditPat();
        }

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
        $base = $this->resolveGitProviderBaseUrl($provider, $this->patApiBaseUrl);
        $profile = $this->fetchGitProviderProfile($provider, $base, $token);
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
        $this->afterGitProviderTokenSaved($provider);
    }

    protected function afterGitProviderTokenSaved(string $provider): void
    {
        //
    }

    private function resolveGitProviderBaseUrl(string $provider, string $userInput): string
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
     * @return array{id: string, nickname: string}|null
     */
    private function fetchGitProviderProfile(string $provider, string $base, string $token): ?array
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
