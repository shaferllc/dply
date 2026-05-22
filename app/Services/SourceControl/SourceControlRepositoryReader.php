<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use Throwable;

/**
 * Read-only browser for a connected git repo across GitHub / GitLab /
 * Bitbucket — lists branches, walks directory trees, reads file blobs,
 * fetches the README and renders it.
 *
 * Same dispatch shape as {@see SiteGitCommitsFetcher} (parse remote URL,
 * look up the viewer's `SocialAccount` for the provider, hit the right
 * REST endpoint, normalise the response). Each method's return value is
 * cached for {@see self::CACHE_TTL_SECONDS} keyed by site + branch + path
 * so the Repository page doesn't re-fetch every render. The Livewire
 * action methods that mutate state (branch switch, repo switch) should
 * call {@see self::invalidate()} after writing, so subsequent reads land
 * on the new branch/repo.
 */
final class SourceControlRepositoryReader
{
    private const CACHE_TTL_SECONDS = 300;

    /** Files larger than this are surfaced as "too large" so the view can fall back to a provider link. */
    private const MAX_FILE_BYTES = 262144;

    /**
     * @return array{
     *     ok: bool,
     *     branches: list<array{name: string, sha: string, committed_at: ?string, committer: ?string, is_default: bool}>,
     *     error: ?string,
     *     provider: ?string,
     * }
     */
    public function branches(Site $site, User $user): array
    {
        return $this->remember($site, 'branches', '', fn () => $this->branchesUncached($site, $user));
    }

    /**
     * @return array{
     *     ok: bool,
     *     entries: list<array{name: string, path: string, type: 'dir'|'file', size: int, sha: ?string}>,
     *     error: ?string,
     *     provider: ?string,
     *     path: string,
     *     branch: string,
     * }
     */
    public function tree(Site $site, User $user, string $branch, string $path = ''): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'tree:'.$branch, $path, fn () => $this->treeUncached($site, $user, $branch, $path));
    }

    /**
     * @return array{
     *     ok: bool,
     *     content: string,
     *     size: int,
     *     too_large: bool,
     *     binary: bool,
     *     html_url: ?string,
     *     error: ?string,
     *     provider: ?string,
     *     path: string,
     *     branch: string,
     * }
     */
    public function file(Site $site, User $user, string $branch, string $path): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'file:'.$branch, $path, fn () => $this->fileUncached($site, $user, $branch, $path));
    }

    /**
     * @return array{
     *     ok: bool,
     *     name: ?string,
     *     content_html: string,
     *     content_raw: string,
     *     error: ?string,
     *     provider: ?string,
     *     branch: string,
     * }
     */
    public function readme(Site $site, User $user, ?string $branch = null): array
    {
        $branch = $branch !== null && $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');

        return $this->remember($site, 'readme:'.$branch, '', fn () => $this->readmeUncached($site, $user, $branch));
    }

    /**
     * Flush every cached read for this site. Call after `git_branch` or
     * `git_repository_url` changes — the page should re-hit the provider
     * APIs on the next render rather than serving stale state.
     */
    public function invalidate(Site $site): void
    {
        // Cache::remember keys are not enumerable; we use a per-site
        // version counter so a single increment invalidates every read
        // without touching every key by hand.
        Cache::increment($this->versionKey($site));
    }

    /* ────────────────────── branches ────────────────────── */

    private function branchesUncached(Site $site, User $user): array
    {
        $remote = $this->parseRemoteUrl($site->git_repository_url);
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

    /**
     * @param  array{provider: string, owner: string, repo: string, label: string}  $remote
     */
    private function githubBranches(array $remote, User $user): array
    {
        $account = $this->tokenAccount($user, 'github');
        if ($account === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitHub account to browse this repo.'), 'provider' => 'github'];
        }

        $repoMeta = $this->githubRepoMeta($remote, $account);
        $defaultBranch = $repoMeta['default_branch'] ?? null;

        $response = $this->githubClient($account)->get(
            'https://api.github.com/repos/'.$remote['owner'].'/'.$remote['repo'].'/branches',
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

    /**
     * @param  array{provider: string, project_path: string, gitlab_api_base: string, label: string}  $remote
     */
    private function gitlabBranches(array $remote, User $user): array
    {
        $account = $this->tokenAccount($user, 'gitlab');
        if ($account === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitLab account to browse this repo.'), 'provider' => 'gitlab'];
        }

        $projectMeta = $this->gitlabProjectMeta($remote, $account);
        $defaultBranch = $projectMeta['default_branch'] ?? null;
        $encoded = rawurlencode($remote['project_path']);
        $url = rtrim($remote['gitlab_api_base'], '/').'/api/v4/projects/'.$encoded.'/repository/branches';

        $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url, ['per_page' => 100]);
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

    /**
     * @param  array{provider: string, workspace: string, repo: string, label: string}  $remote
     */
    private function bitbucketBranches(array $remote, User $user): array
    {
        $account = $this->tokenAccount($user, 'bitbucket');
        if ($account === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a Bitbucket account to browse this repo.'), 'provider' => 'bitbucket'];
        }

        $url = 'https://api.bitbucket.org/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/refs/branches';
        $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url, ['pagelen' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $defaultBranch = $this->bitbucketDefaultBranch($remote, $account);

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

    /* ────────────────────── tree ────────────────────── */

    private function treeUncached(Site $site, User $user, string $branch, string $path): array
    {
        $remote = $this->parseRemoteUrl($site->git_repository_url);
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
        $account = $this->tokenAccount($user, 'github');
        if ($account === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitHub account.'), 'provider' => 'github'];
        }

        $url = 'https://api.github.com/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $response = $this->githubClient($account)->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $rows = $response->json();
        if (! is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1)) {
            // The contents endpoint returns an object for a single-file
            // path; treat that as "not a directory" rather than crashing.
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
        $account = $this->tokenAccount($user, 'gitlab');
        if ($account === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitLab account.'), 'provider' => 'gitlab'];
        }

        $encoded = rawurlencode($remote['project_path']);
        $url = rtrim($remote['gitlab_api_base'], '/').'/api/v4/projects/'.$encoded.'/repository/tree';
        $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url, [
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
                'size' => 0, // GitLab tree endpoint doesn't return sizes
                'sha' => isset($row['id']) ? (string) $row['id'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'gitlab'];
    }

    private function bitbucketTree(array $remote, User $user, string $branch, string $path): array
    {
        $account = $this->tokenAccount($user, 'bitbucket');
        if ($account === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a Bitbucket account.'), 'provider' => 'bitbucket'];
        }

        $segment = $path === '' ? '' : $this->encodePath($path).'/';
        $url = 'https://api.bitbucket.org/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$segment;
        $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url);
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
        $remote = $this->parseRemoteUrl($site->git_repository_url);
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
        $account = $this->tokenAccount($user, 'github');
        if ($account === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitHub account.'), 'provider' => 'github'];
        }

        $url = 'https://api.github.com/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $htmlUrl = 'https://github.com/'.$remote['owner'].'/'.$remote['repo'].'/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = $this->githubClient($account)->get($url, ['ref' => $branch]);
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
        $account = $this->tokenAccount($user, 'gitlab');
        if ($account === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitLab account.'), 'provider' => 'gitlab'];
        }

        $encodedProject = rawurlencode($remote['project_path']);
        $encodedPath = rawurlencode($path);
        $url = rtrim($remote['gitlab_api_base'], '/').'/api/v4/projects/'.$encodedProject.'/repository/files/'.$encodedPath;
        $htmlUrl = rtrim($remote['gitlab_api_base'], '/').'/'.$remote['project_path'].'/-/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url, ['ref' => $branch]);
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
        $account = $this->tokenAccount($user, 'bitbucket');
        if ($account === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a Bitbucket account.'), 'provider' => 'bitbucket'];
        }

        $url = 'https://api.bitbucket.org/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $htmlUrl = 'https://bitbucket.org/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken((string) $account->access_token)->get($url);
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
        $remote = $this->parseRemoteUrl($site->git_repository_url);
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
        $account = $this->tokenAccount($user, 'github');
        if ($account === null) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Link a GitHub account.'), 'provider' => 'github'];
        }

        $url = 'https://api.github.com/repos/'.$remote['owner'].'/'.$remote['repo'].'/readme';
        $response = $this->githubClient($account)->get($url, ['ref' => $branch]);
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

    /**
     * Probe a list of common README names. First hit wins.
     */
    private function probeReadmeViaFile(array $remote, User $user, string $branch, string $provider): array
    {
        $site = new Site;
        $site->git_repository_url = $this->urlFromRemote($remote);

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

    /**
     * Repo metadata (most importantly `default_branch`). Fetched once
     * per branches() call but kept private so other code paths can mark
     * the right branch without an extra API hit.
     */
    private function githubRepoMeta(array $remote, SocialAccount $account): array
    {
        try {
            $response = $this->githubClient($account)->get('https://api.github.com/repos/'.$remote['owner'].'/'.$remote['repo']);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore — falling back to no default-branch hint
        }

        return [];
    }

    private function gitlabProjectMeta(array $remote, SocialAccount $account): array
    {
        try {
            $encoded = rawurlencode($remote['project_path']);
            $url = rtrim($remote['gitlab_api_base'], '/').'/api/v4/projects/'.$encoded;
            $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore
        }

        return [];
    }

    private function bitbucketDefaultBranch(array $remote, SocialAccount $account): ?string
    {
        try {
            $url = 'https://api.bitbucket.org/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'];
            $response = Http::withToken((string) $account->access_token)->acceptJson()->get($url);
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

    private function githubClient(SocialAccount $account)
    {
        return Http::withHeaders([
            'User-Agent' => 'Dply (repo-reader)',
            'Accept' => 'application/vnd.github+json',
        ])->withToken((string) $account->access_token)->acceptJson();
    }

    private function tokenAccount(User $user, string $provider): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();
    }

    /**
     * Same parsing logic as {@see SiteGitCommitsFetcher::parseRemoteUrl()}
     * — duplicated here rather than extracting to a shared trait because
     * the existing fetcher is stable and we want to avoid disturbing it.
     *
     * @return array{provider: string, label: string, owner?: string, repo?: string, project_path?: string, workspace?: string, gitlab_api_base?: string}|null
     */
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

    /**
     * Reverse of {@see parseRemoteUrl} — only used internally where
     * {@see probeReadmeViaFile} needs a synthetic Site for cache scoping.
     */
    private function urlFromRemote(array $remote): string
    {
        return match ($remote['provider']) {
            'github' => 'https://github.com/'.$remote['owner'].'/'.$remote['repo'],
            'bitbucket' => 'https://bitbucket.org/'.$remote['workspace'].'/'.$remote['repo'],
            'gitlab' => rtrim($remote['gitlab_api_base'], '/').'/'.$remote['project_path'],
            default => '',
        };
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param  list<array{name: string, path: string, type: 'dir'|'file', size: int, sha: ?string}>  $entries
     * @return list<array{name: string, path: string, type: 'dir'|'file', size: int, sha: ?string}>
     */
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

    /**
     * Cheap binary sniff — if there's a NUL byte in the first 1024 bytes
     * we treat the file as binary and skip rendering it. Standard text
     * formats (UTF-8, ASCII) don't contain NULs.
     */
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

    /**
     * Wrap a callable in a per-site, version-scoped cache. The version
     * counter at {@see versionKey} makes {@see invalidate()} one increment
     * rather than a per-key forget loop.
     *
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return T
     */
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
