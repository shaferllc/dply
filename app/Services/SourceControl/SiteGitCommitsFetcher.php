<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Loads recent commits for a site's configured Git remote using the viewer's
 * best {@see GitIdentity} (OAuth account or PAT) for that provider, via
 * {@see GitIdentityResolver}.
 */
final class SiteGitCommitsFetcher
{
    private const MAX_COMMITS = 50;

    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= new GitIdentityResolver;
    }

    /**
     * @return array{
     *     ok: bool,
     *     commits: list<array{
     *         sha: string,
     *         short_sha: string,
     *         message: string,
     *         author_name: string,
     *         author_email: string|null,
     *         committed_at: string|null,
     *         html_url: string|null,
     *     }>,
     *     error: string|null,
     *     provider: string|null,
     *     branch: string,
     *     remote_label: string|null,
     * }
     */
    public function fetch(Site $site, User $user, int $limit = 30, ?string $branchOverride = null, int $page = 1): array
    {
        $branch = $branchOverride !== null && $branchOverride !== ''
            ? $branchOverride
            : (string) ($site->git_branch ?: 'main');
        $remote = $this->parseRemoteUrl($site->sourceControlRepositoryUrl());
        if ($remote === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Add a Git repository URL in Deploy settings to list commits.'),
                'provider' => null,
                'branch' => $branch,
                'remote_label' => null,
                'page' => 1,
                'has_more' => false,
            ];
        }

        $limit = max(1, min(self::MAX_COMMITS, $limit));
        $page = max(1, $page);

        return match ($remote['provider']) {
            'github' => $this->fetchGithub($remote, $site, $user, $branch, $limit, $page),
            'gitlab' => $this->fetchGitlab($remote, $site, $user, $branch, $limit, $page),
            'bitbucket' => $this->fetchBitbucket($remote, $site, $user, $branch, $limit, $page),
            default => [
                'ok' => false,
                'commits' => [],
                'error' => __('Unsupported Git host. Use GitHub, GitLab, or Bitbucket for commit browsing.'),
                'provider' => $remote['provider'],
                'branch' => $branch,
                'remote_label' => $remote['label'] ?? null,
                'page' => 1,
                'has_more' => false,
            ],
        };
    }

    private function parseRemoteUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, 'git@')) {
            $colonPos = strpos($url, ':');
            if ($colonPos === false) {
                return null;
            }
            $host = strtolower(substr($url, 4, $colonPos - 4));
            $path = substr($url, $colonPos + 1);
            $path = preg_replace('/\.git$/', '', (string) $path) ?? '';

            return $this->remoteFromHostAndPath($host, $path);
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('/\.git$/', '', $path) ?? '';

        return $this->remoteFromHostAndPath($host, $path);
    }

    private function remoteFromHostAndPath(string $host, string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        if ($host === 'github.com' || str_ends_with($host, '.github.com')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return [
                'provider' => 'github',
                'owner' => $segments[0],
                'repo' => $segments[1],
                'label' => $segments[0].'/'.$segments[1],
            ];
        }

        if (str_contains($host, 'bitbucket.org')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return [
                'provider' => 'bitbucket',
                'workspace' => $segments[0],
                'repo' => $segments[1],
                'label' => $segments[0].'/'.$segments[1],
            ];
        }

        if (str_contains($host, 'gitlab')) {
            $apiBase = 'https://'.$host;

            return [
                'provider' => 'gitlab',
                'project_path' => $path,
                'gitlab_api_base' => $apiBase,
                'label' => $path,
            ];
        }

        return null;
    }

    private function fetchGithub(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user,'github');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a GitHub account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'github',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Dply (git-commits)',
            'Accept' => 'application/vnd.github+json',
        ])
            ->withToken($identity->accessToken())
            ->acceptJson()
            ->get($identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/commits', [
                'sha' => $branch,
                'per_page' => $limit,
                'page' => $page,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'github',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Unexpected response from GitHub.'),
                'provider' => 'github',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sha = (string) ($row['sha'] ?? '');
            if ($sha === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
            $message = (string) ($commit['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;

            $commits[] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($author['name'] ?? __('Unknown')),
                'author_email' => isset($author['email']) ? (string) $author['email'] : null,
                'committed_at' => isset($author['date']) ? (string) $author['date'] : null,
                'html_url' => isset($row['html_url']) ? (string) $row['html_url'] : null,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'provider' => 'github',
            'branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => count($commits) >= $limit,
        ];
    }

    private function fetchGitlab(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user,'gitlab');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a GitLab account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'gitlab',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $encoded = rawurlencode($remote['project_path']);
        $apiBase = $this->gitlabApiBase($identity, $remote);
        $url = $apiBase.'/api/v4/projects/'.$encoded.'/repository/commits';

        $response = Http::withToken($identity->accessToken())
            ->acceptJson()
            ->get($url, [
                'ref_name' => $branch,
                'per_page' => $limit,
                'page' => $page,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'gitlab',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Unexpected response from GitLab.'),
                'provider' => 'gitlab',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $message = (string) ($row['title'] ?? $row['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;

            $commits[] = [
                'sha' => $id,
                'short_sha' => (string) ($row['short_id'] ?? substr($id, 0, 7)),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($row['author_name'] ?? __('Unknown')),
                'author_email' => isset($row['author_email']) ? (string) $row['author_email'] : null,
                'committed_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'html_url' => isset($row['web_url']) ? (string) $row['web_url'] : null,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'provider' => 'gitlab',
            'branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => count($commits) >= $limit,
        ];
    }

    private function fetchBitbucket(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user,'bitbucket');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a Bitbucket account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'bitbucket',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/commits/'.$branch;

        $response = Http::withToken($identity->accessToken())
            ->acceptJson()
            ->get($url, ['pagelen' => $limit, 'page' => $page]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'bitbucket',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        // Bitbucket returns a `next` URL when more pages exist — authoritative.
        $hasMore = isset($payload['next']) && (string) $payload['next'] !== '';

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hash = (string) ($row['hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $message = (string) ($row['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;
            $author = is_array($row['author'] ?? null) ? $row['author'] : [];
            $userInfo = is_array($author['user'] ?? null) ? $author['user'] : [];
            $html = is_array($row['links']['html'] ?? null) ? $row['links']['html'] : [];
            $htmlHref = isset($html['href']) ? (string) $html['href'] : null;

            $commits[] = [
                'sha' => $hash,
                'short_sha' => substr($hash, 0, 7),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($userInfo['display_name'] ?? $userInfo['username'] ?? __('Unknown')),
                'author_email' => null,
                'committed_at' => isset($row['date']) ? (string) $row['date'] : null,
                'html_url' => $htmlHref,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'provider' => 'bitbucket',
            'branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => $hasMore,
        ];
    }

    private function gitlabApiBase(GitIdentity $identity, array $remote): string
    {
        $base = $identity->apiBaseUrl();
        if ($base !== '' && $base !== 'https://gitlab.com') {
            return rtrim($base, '/');
        }

        $fromRemote = (string) ($remote['gitlab_api_base'] ?? '');

        return $fromRemote !== '' ? rtrim($fromRemote, '/') : rtrim($base, '/');
    }

    private function formatApiError(int $status, string $body): string
    {
        $snippet = Str::limit(trim($body), 200);

        return __('Git provider returned :status.', ['status' => (string) $status]).($snippet !== '' ? ' '.$snippet : '');
    }
}
