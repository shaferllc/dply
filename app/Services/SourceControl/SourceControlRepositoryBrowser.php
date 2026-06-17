<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class SourceControlRepositoryBrowser
{
    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    /**
     * @return list<array{id: string, provider: string, label: string, kind: string}>
     */
    /** @return array<string, mixed> */
    public function accountsForUser(User $user): array
    {
        $cacheKey = 'source-control.accounts.'.(string) $user->getKey();
        $cached = request()->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $accounts = array_map(
            fn (GitIdentity $identity): array => [
                'id' => $identity->id(),
                'provider' => $identity->provider(),
                'label' => $identity->displayLabel(),
                'kind' => $identity->kind(),
            ],
            $this->resolver->allForUser($user),
        );

        request()->attributes->set($cacheKey, $accounts);

        return $accounts;
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function repositoriesForAccount(GitIdentity $account): array
    {
        return match ($account->provider()) {
            'github' => $this->githubRepositories($account),
            'gitlab' => $this->gitlabRepositories($account),
            'bitbucket' => $this->bitbucketRepositories($account),
            default => [],
        };
    }

    public function authenticatedCloneUrl(GitIdentity $account, string $repositoryUrl): string
    {
        $token = $account->accessToken();
        if ($token === '') {
            return $repositoryUrl;
        }

        $parts = parse_url($repositoryUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $repositoryUrl;
        }

        $user = match ($account->provider()) {
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

    /**
     * @return list<array<string, string>>
     */
    private function githubRepositories(GitIdentity $account): array
    {
        $response = Http::withToken($account->accessToken())
            ->acceptJson()
            ->get($account->apiBaseUrl().'/user/repos', [
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
    private function gitlabRepositories(GitIdentity $account): array
    {
        $response = Http::withToken($account->accessToken())
            ->acceptJson()
            ->get($account->apiBaseUrl().'/api/v4/projects', [
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
    private function bitbucketRepositories(GitIdentity $account): array
    {
        $response = Http::withToken($account->accessToken())
            ->acceptJson()
            ->get($account->apiBaseUrl().'/2.0/repositories', [
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
