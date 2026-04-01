<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class SourceControlRepositoryBrowser
{
    /**
     * @return list<array{id: string, provider: string, label: string}>
     */
    public function accountsForUser(User $user): array
    {
        return $user->socialAccounts()
            ->whereIn('provider', ['github', 'gitlab', 'bitbucket'])
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => (string) $account->id,
                'provider' => $account->provider,
                'label' => $this->accountLabel($account),
            ])
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    public function repositoriesForAccount(SocialAccount $account): array
    {
        return match ($account->provider) {
            'github' => $this->githubRepositories($account),
            'gitlab' => $this->gitlabRepositories($account),
            'bitbucket' => $this->bitbucketRepositories($account),
            default => [],
        };
    }

    public function authenticatedCloneUrl(SocialAccount $account, string $repositoryUrl): string
    {
        $token = trim((string) $account->access_token);
        if ($token === '') {
            return $repositoryUrl;
        }

        $parts = parse_url($repositoryUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $repositoryUrl;
        }

        $user = match ($account->provider) {
            'github' => 'x-access-token',
            'gitlab' => 'oauth2',
            'bitbucket' => 'x-token-auth',
            default => '',
        };

        if ($user === '') {
            return $repositoryUrl;
        }

        $auth = rawurlencode($user).':'.rawurlencode($token).'@'.$parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $parts['scheme'].'://'.$auth.$port.$path.$query;
    }

    private function accountLabel(SocialAccount $account): string
    {
        $provider = ucfirst($account->provider);
        $nickname = trim((string) ($account->label ?: $account->nickname ?: $account->provider_id));

        return $provider.($nickname !== '' ? ' - '.$nickname : '');
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    private function githubRepositories(SocialAccount $account): array
    {
        $response = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->get('https://api.github.com/user/repos', [
                'sort' => 'updated',
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json())
            ->filter(fn (mixed $repo): bool => is_array($repo) && is_string($repo['clone_url'] ?? null))
            ->map(fn (array $repo): array => [
                'label' => (string) ($repo['full_name'] ?? $repo['name'] ?? $repo['clone_url']),
                'url' => (string) $repo['clone_url'],
                'branch' => (string) ($repo['default_branch'] ?? 'main'),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    private function gitlabRepositories(SocialAccount $account): array
    {
        $response = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->get('https://gitlab.com/api/v4/projects', [
                'membership' => true,
                'simple' => true,
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json())
            ->filter(fn (mixed $repo): bool => is_array($repo) && is_string($repo['http_url_to_repo'] ?? null))
            ->map(fn (array $repo): array => [
                'label' => (string) ($repo['path_with_namespace'] ?? $repo['name'] ?? $repo['http_url_to_repo']),
                'url' => (string) $repo['http_url_to_repo'],
                'branch' => (string) ($repo['default_branch'] ?? 'main'),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    private function bitbucketRepositories(SocialAccount $account): array
    {
        $response = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->get('https://api.bitbucket.org/2.0/repositories', [
                'role' => 'member',
                'pagelen' => 100,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('values', []))
            ->filter(fn (mixed $repo): bool => is_array($repo))
            ->map(function (array $repo): ?array {
                $cloneUrl = collect($repo['links']['clone'] ?? [])
                    ->firstWhere('name', 'https')['href'] ?? null;

                if (! is_string($cloneUrl) || $cloneUrl === '') {
                    return null;
                }

                return [
                    'label' => (string) ($repo['full_name'] ?? $repo['name'] ?? $cloneUrl),
                    'url' => $cloneUrl,
                    'branch' => (string) ($repo['mainbranch']['name'] ?? 'main'),
                ];
            })
            ->filter()
            ->sortBy('label')
            ->values()
            ->all();
    }
}
