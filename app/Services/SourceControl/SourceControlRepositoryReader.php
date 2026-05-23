<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;

/**
 * Read-only browser for a connected git repo across GitHub / GitLab /
 * Bitbucket — lists branches, walks directory trees, reads file blobs,
 * fetches the README and renders it.
 *
 * Same dispatch shape as {@see SiteGitCommitsFetcher}: parse the remote URL,
 * resolve the viewer's best {@see GitIdentity} for that provider (OAuth or
 * PAT, via {@see GitIdentityResolver}), hit the right REST endpoint using
 * the identity's API base. Each method's return value is cached for
 * {@see self::CACHE_TTL_SECONDS} keyed by site + branch + path so the
 * Repository page doesn't re-fetch every render. Mutating actions (branch
 * switch, repo switch) must call {@see self::invalidate()}.
 */
final class SourceControlRepositoryReader
{
    private const CACHE_TTL_SECONDS = 300;

    /** Files larger than this are surfaced as "too large" so the view can fall back to a provider link. */
    private const MAX_FILE_BYTES = 262144;

    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= new GitIdentityResolver;
    }

    public function branches(Site $site, User $user): array
    {
        return $this->remember($site, 'branches', '', fn () => $this->branchesUncached($site, $user));
    }

    public function tags(Site $site, User $user): array
    {
        return $this->remember($site, 'tags', '', fn () => $this->tagsUncached($site, $user));
    }

    public function tree(Site $site, User $user, string $branch, string $path = ''): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'tree:'.$branch, $path, fn () => $this->treeUncached($site, $user, $branch, $path));
    }

    public function file(Site $site, User $user, string $branch, string $path): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'file:'.$branch, $path, fn () => $this->fileUncached($site, $user, $branch, $path));
    }

    public function readme(Site $site, User $user, ?string $branch = null): array
    {
        $branch = $branch !== null && $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');

        return $this->remember($site, 'readme:'.$branch, '', fn () => $this->readmeUncached($site, $user, $branch));
    }

    public function invalidate(Site $site): void
    {
        Cache::increment($this->versionKey($site));
    }

    /* ────────────────────── branches ────────────────────── */

    private function branchesUncached(Site $site, User $user): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null];
        }

        return match ($remote['provider']) {
            'github' => $this->githubBranches($remote, $user),
            'gitlab' => $this->gitlabBranches($remote, $user),
            'bitbucket' => $this->bitbucketBranches($remote, $user),
            default => ['ok' => false, 'branches' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };
    }

    private function githubBranches(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitHub account or add a personal access token to browse this repo.'), 'provider' => 'github'];
        }

        $repoMeta = $this->githubRepoMeta($remote, $identity);
        $defaultBranch = $repoMeta['default_branch'] ?? null;

        $response = $this->githubClient($identity)->get(
            $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/branches',
            ['per_page' => 100],
        );
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $rows = is_array($response->json()) ? $response->json() : [];

        $branches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $branches[] = [
                'name' => $name,
                'sha' => (string) ($commit['sha'] ?? ''),
                'committed_at' => null,
                'committer' => null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'github'];
    }

    private function gitlabBranches(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitLab account or add a personal access token to browse this repo.'), 'provider' => 'gitlab'];
        }

        $projectMeta = $this->gitlabProjectMeta($remote, $identity);
        $defaultBranch = $projectMeta['default_branch'] ?? null;
        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/branches';

        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['per_page' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }
        $rows = is_array($response->json()) ? $response->json() : [];

        $branches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $branches[] = [
                'name' => $name,
                'sha' => (string) ($commit['id'] ?? ''),
                'committed_at' => isset($commit['committed_date']) ? (string) $commit['committed_date'] : null,
                'committer' => isset($commit['committer_name']) ? (string) $commit['committer_name'] : null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'gitlab'];
    }

    private function bitbucketBranches(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a Bitbucket account or add a personal access token to browse this repo.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/refs/branches';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['pagelen' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $defaultBranch = $this->bitbucketDefaultBranch($remote, $identity);

        $branches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $target = is_array($row['target'] ?? null) ? $row['target'] : [];
            $branches[] = [
                'name' => $name,
                'sha' => (string) ($target['hash'] ?? ''),
                'committed_at' => isset($target['date']) ? (string) $target['date'] : null,
                'committer' => null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'bitbucket'];
    }

    private function tagsUncached(Site $site, User $user): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null];
        }

        return match ($remote['provider']) {
            'github' => $this->githubTags($remote, $user),
            'gitlab' => $this->gitlabTags($remote, $user),
            'bitbucket' => $this->bitbucketTags($remote, $user),
            default => ['ok' => false, 'tags' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };
    }

    private function githubTags(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a GitHub account or add a personal access token to browse this repo.'), 'provider' => 'github'];
        }

        $response = $this->githubClient($identity)->get(
            $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/tags',
            ['per_page' => 100],
        );
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }

        $tags = [];
        foreach (is_array($response->json()) ? $response->json() : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $tags[] = [
                'name' => $name,
                'sha' => (string) ($commit['sha'] ?? ''),
                'committed_at' => null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'github'];
    }

    private function gitlabTags(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a GitLab account or add a personal access token to browse this repo.'), 'provider' => 'gitlab'];
        }

        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/tags';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['per_page' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }

        $tags = [];
        foreach (is_array($response->json()) ? $response->json() : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $tags[] = [
                'name' => $name,
                'sha' => (string) ($commit['id'] ?? ''),
                'committed_at' => isset($commit['committed_date']) ? (string) $commit['committed_date'] : null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'gitlab'];
    }

    private function bitbucketTags(array $remote, User $user): array
    {
        $identity = $this->resolver->forUserProvider($user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a Bitbucket account or add a personal access token to browse this repo.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/refs/tags';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['pagelen' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }

        $rows = is_array($response->json('values')) ? $response->json('values') : [];
        $tags = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $target = is_array($row['target'] ?? null) ? $row['target'] : [];
            $tags[] = [
                'name' => $name,
                'sha' => (string) ($target['hash'] ?? ''),
                'committed_at' => isset($target['date']) ? (string) $target['date'] : null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'bitbucket'];
    }

    /* ────────────────────── tree ────────────────────── */

    private function treeUncached(Site $site, User $user, string $branch, string $path): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'path' => $path, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubTree($remote, $user, $branch, $path),
            'gitlab' => $this->gitlabTree($remote, $user, $branch, $path),
            'bitbucket' => $this->bitbucketTree($remote, $user, $branch, $path),
            default => ['ok' => false, 'entries' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['path' => $path, 'branch' => $branch];
    }

    private function githubTree(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $rows = $response->json();
        if (! is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1)) {
            return ['ok' => false, 'entries' => [], 'error' => __('This path is a file, not a directory.'), 'provider' => 'github'];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'file')) === 'dir' ? 'dir' : 'file';
            $entries[] = [
                'name' => (string) ($row['name'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'type' => $type,
                'size' => (int) ($row['size'] ?? 0),
                'sha' => isset($row['sha']) ? (string) $row['sha'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'github'];
    }

    private function gitlabTree(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitLab account or add a personal access token.'), 'provider' => 'gitlab'];
        }

        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/tree';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, [
            'ref' => $branch,
            'path' => $path,
            'per_page' => 100,
        ]);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }
        $rows = is_array($response->json()) ? $response->json() : [];

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'blob')) === 'tree' ? 'dir' : 'file';
            $entries[] = [
                'name' => (string) ($row['name'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'type' => $type,
                'size' => 0,
                'sha' => isset($row['id']) ? (string) $row['id'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'gitlab'];
    }

    private function bitbucketTree(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a Bitbucket account or add a personal access token.'), 'provider' => 'bitbucket'];
        }

        $segment = $path === '' ? '' : $this->encodePath($path).'/';
        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$segment;
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'commit_file')) === 'commit_directory' ? 'dir' : 'file';
            $rowPath = (string) ($row['path'] ?? '');
            $name = (string) Str::afterLast($rowPath, '/');
            if ($name === '') {
                $name = $rowPath;
            }
            $entries[] = [
                'name' => $name,
                'path' => $rowPath,
                'type' => $type,
                'size' => (int) ($row['size'] ?? 0),
                'sha' => isset($row['commit']['hash']) ? (string) $row['commit']['hash'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'bitbucket'];
    }

    /* ────────────────────── file ────────────────────── */

    private function fileUncached(Site $site, User $user, string $branch, string $path): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'path' => $path, 'branch' => $branch];
        }
        if ($path === '') {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Empty file path.'), 'provider' => $remote['provider'], 'path' => $path, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubFile($remote, $user, $branch, $path),
            'gitlab' => $this->gitlabFile($remote, $user, $branch, $path),
            'bitbucket' => $this->bitbucketFile($remote, $user, $branch, $path),
            default => ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['path' => $path, 'branch' => $branch];
    }

    private function githubFile(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $htmlUrl = 'https://github.com/'.$remote['owner'].'/'.$remote['repo'].'/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $row = $response->json();
        if (! is_array($row) || array_keys($row) === range(0, count($row) - 1)) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => __('Path is a directory, not a file.'), 'provider' => 'github'];
        }

        $size = (int) ($row['size'] ?? 0);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'github'];
        }
        $raw = base64_decode((string) ($row['content'] ?? ''), true);
        if ($raw === false) {
            return ['ok' => false, 'content' => '', 'size' => $size, 'too_large' => false, 'binary' => true, 'html_url' => $htmlUrl, 'error' => __('Could not decode file contents.'), 'provider' => 'github'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'github');
    }

    private function gitlabFile(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitLab account or add a personal access token.'), 'provider' => 'gitlab'];
        }

        $encodedProject = rawurlencode($remote['project_path']);
        $encodedPath = rawurlencode($path);
        $apiBase = $this->gitlabApiBase($identity, $remote);
        $url = $apiBase.'/api/v4/projects/'.$encodedProject.'/repository/files/'.$encodedPath;
        $htmlUrl = $apiBase.'/'.$remote['project_path'].'/-/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }
        $row = is_array($response->json()) ? $response->json() : [];

        $size = (int) ($row['size'] ?? 0);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'gitlab'];
        }
        $encoding = (string) ($row['encoding'] ?? 'base64');
        $contentRaw = (string) ($row['content'] ?? '');
        $raw = $encoding === 'base64' ? base64_decode($contentRaw, true) : $contentRaw;
        if ($raw === false) {
            return ['ok' => false, 'content' => '', 'size' => $size, 'too_large' => false, 'binary' => true, 'html_url' => $htmlUrl, 'error' => __('Could not decode file contents.'), 'provider' => 'gitlab'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'gitlab');
    }

    private function bitbucketFile(array $remote, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forUserProvider($user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a Bitbucket account or add a personal access token.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $htmlUrl = 'https://bitbucket.org/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken($identity->accessToken())->get($url);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $raw = $response->body();
        $size = strlen($raw);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'bitbucket'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'bitbucket');
    }

    private function buildFileResult(string $raw, int $size, string $htmlUrl, string $provider): array
    {
        $binary = $this->looksBinary($raw);
        if ($size === 0) {
            $size = strlen($raw);
        }

        return [
            'ok' => true,
            'content' => $binary ? '' : $raw,
            'size' => $size,
            'too_large' => false,
            'binary' => $binary,
            'html_url' => $htmlUrl,
            'error' => null,
            'provider' => $provider,
        ];
    }

    /* ────────────────────── readme ────────────────────── */

    private function readmeUncached(Site $site, User $user, string $branch): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubReadme($remote, $user, $branch),
            'gitlab' => $this->probeReadmeViaFile($remote, $user, $branch, 'gitlab'),
            'bitbucket' => $this->probeReadmeViaFile($remote, $user, $branch, 'bitbucket'),
            default => ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['branch' => $branch];
    }

    private function githubReadme(array $remote, User $user, string $branch): array
    {
        $identity = $this->resolver->forUserProvider($user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/readme';
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if ($response->status() === 404) {
            return ['ok' => true, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => null, 'provider' => 'github'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $row = is_array($response->json()) ? $response->json() : [];
        $raw = base64_decode((string) ($row['content'] ?? ''), true);
        if ($raw === false) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Could not decode README.'), 'provider' => 'github'];
        }

        return [
            'ok' => true,
            'name' => isset($row['name']) ? (string) $row['name'] : 'README.md',
            'content_html' => $this->renderMarkdown($raw),
            'content_raw' => $raw,
            'error' => null,
            'provider' => 'github',
        ];
    }

    private function probeReadmeViaFile(array $remote, User $user, string $branch, string $provider): array
    {
        foreach (['README.md', 'readme.md', 'Readme.md', 'README', 'README.rst', 'README.txt'] as $candidate) {
            $file = match ($provider) {
                'gitlab' => $this->gitlabFile($remote, $user, $branch, $candidate),
                'bitbucket' => $this->bitbucketFile($remote, $user, $branch, $candidate),
                default => null,
            };
            if (! is_array($file) || ! ($file['ok'] ?? false) || ($file['too_large'] ?? false) || ($file['binary'] ?? false)) {
                continue;
            }
            $raw = (string) ($file['content'] ?? '');
            if ($raw === '') {
                continue;
            }

            return [
                'ok' => true,
                'name' => $candidate,
                'content_html' => $this->renderMarkdown($raw),
                'content_raw' => $raw,
                'error' => null,
                'provider' => $provider,
            ];
        }

        return ['ok' => true, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => null, 'provider' => $provider];
    }

    private function renderMarkdown(string $raw): string
    {
        try {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new GithubFlavoredMarkdownExtension);
            $converter = new MarkdownConverter($environment);

            return (string) $converter->convert($raw);
        } catch (Throwable) {
            return '<pre>'.e($raw).'</pre>';
        }
    }

    /* ────────────────────── helpers ────────────────────── */

    private function githubRepoMeta(array $remote, GitIdentity $identity): array
    {
        try {
            $response = $this->githubClient($identity)->get($identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo']);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore — falling back to no default-branch hint
        }

        return [];
    }

    private function gitlabProjectMeta(array $remote, GitIdentity $identity): array
    {
        try {
            $encoded = rawurlencode($remote['project_path']);
            $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded;
            $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore
        }

        return [];
    }

    private function bitbucketDefaultBranch(array $remote, GitIdentity $identity): ?string
    {
        try {
            $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'];
            $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
            if ($response->successful()) {
                $body = $response->json();
                $name = $body['mainbranch']['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function githubClient(GitIdentity $identity)
    {
        return Http::withHeaders([
            'User-Agent' => 'Dply (repo-reader)',
            'Accept' => 'application/vnd.github+json',
        ])->withToken($identity->accessToken())->acceptJson();
    }

    /**
     * GitLab's REST API is rooted on the host (no /api/v3 prefix), but the
     * repo's host may differ from a user's PAT base URL (e.g. a cloud
     * gitlab.com repo with no PAT yet, or a self-hosted PAT pointed at a
     * different host). Prefer the identity's configured base; fall back
     * to the host parsed from the repo URL.
     */
    private function gitlabApiBase(GitIdentity $identity, array $remote): string
    {
        $base = $identity->apiBaseUrl();
        if ($base !== '' && $base !== 'https://gitlab.com') {
            return rtrim($base, '/');
        }

        $fromRemote = (string) ($remote['gitlab_api_base'] ?? '');

        return $fromRemote !== '' ? rtrim($fromRemote, '/') : rtrim($base, '/');
    }

    private function remoteForSite(Site $site): ?array
    {
        return $this->parseRemoteUrl($site->sourceControlRepositoryUrl());
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
            $path = (string) preg_replace('/\.git$/', '', substr($url, $colonPos + 1));

            return $this->remoteFromHostAndPath($host, $path);
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $path = (string) preg_replace('/\.git$/', '', $path);

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

            return ['provider' => 'github', 'owner' => $segments[0], 'repo' => $segments[1], 'label' => $segments[0].'/'.$segments[1]];
        }
        if (str_contains($host, 'bitbucket.org')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return ['provider' => 'bitbucket', 'workspace' => $segments[0], 'repo' => $segments[1], 'label' => $segments[0].'/'.$segments[1]];
        }
        if (str_contains($host, 'gitlab')) {
            return ['provider' => 'gitlab', 'project_path' => $path, 'gitlab_api_base' => 'https://'.$host, 'label' => $path];
        }

        return null;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function sortEntries(array $entries): array
    {
        usort($entries, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_values($entries);
    }

    private function looksBinary(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        $sample = substr($raw, 0, 1024);

        return str_contains($sample, "\0");
    }

    private function formatApiError(int $status, string $body): string
    {
        $snippet = Str::limit(trim($body), 200);

        return __('Git provider returned :status.', ['status' => (string) $status]).($snippet !== '' ? ' '.$snippet : '');
    }

    /* ────────────────────── cache ────────────────────── */

    private function remember(Site $site, string $method, string $path, callable $resolver)
    {
        $version = (int) Cache::get($this->versionKey($site), 0);
        $key = 'repo:reader:'.$site->id.':v'.$version.':'.md5($method.'|'.$path);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $resolver);
    }

    private function versionKey(Site $site): string
    {
        return 'repo:reader:v:'.$site->id;
    }
}
