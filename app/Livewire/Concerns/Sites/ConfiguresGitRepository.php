<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Sites;

use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\Http;

/**
 * Shared "Configure Git repository" picker state + behaviour for the surfaces
 * that connect a Site to a full clone URL: the choose-app step, the custom-site
 * create flow, and the Repository connection tab.
 *
 * It owns the canonical picker properties and the Livewire `updated*` hooks that
 * keep them in sync (source toggle → account → repository list → URL/branch).
 * The blade partial `livewire.sites.partials._git-repository-configurator`
 * renders against exactly these property names.
 *
 * Hosts customise behaviour through the protected `on*` hooks (all default
 * no-ops) rather than redeclaring the `updated*` methods — Livewire matches the
 * magic hooks by name, so a host-level redeclaration would shadow the shared
 * body. The hooks let each host inject only its deltas (e.g. ChooseApp/Repository
 * persist the URL+account onto the Site so the ref picker can resolve refs).
 *
 * Designed to co-exist with {@see \App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts}
 * (which refreshes {@see $linkedSourceControlAccounts} when a provider is linked
 * mid-flow) and, on the Site-backed hosts, {@see PicksRepositoryRef}. Member
 * names are disjoint from both.
 *
 * NB: this trait does NOT declare `$site`; only the Site-backed hosts have one,
 * and only their hook implementations touch it.
 */
trait ConfiguresGitRepository
{
    /**
     * Repository source for the picker:
     *  - 'provider': choose from a connected git account's repositories
     *  - 'manual':   paste a repository URL
     */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    /** Chosen repository clone URL while in provider mode. */
    public string $repository_selection = '';

    public string $git_repository_url = '';

    public string $git_branch = 'main';

    /** Ref kind for $git_branch: 'branch' | 'tag' | 'commit' | null (≈ branch). */
    public ?string $git_ref_kind = null;

    /**
     * State of the public-repo scan in manual mode.
     * 'idle' = no scan attempted, 'found' = success, 'not_found' | 'error' = failure.
     */
    public string $repoScanState = 'idle';

    /**
     * Metadata returned by the public provider API after a successful scan.
     *
     * @var array{provider?: string, name?: string, description?: string, visibility?: string, default_branch?: string, stars?: int}
     */
    public array $scannedRepoMeta = [];

    /**
     * Branch list from the public provider API (populated alongside scannedRepoMeta).
     *
     * @var list<array{name: string, default: bool}>
     */
    public array $scannedBranches = [];

    /**
     * Connected source-control accounts for the current user.
     *
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * Repositories surfaced from the selected account.
     *
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableRepositories = [];

    public function updatedRepoSource(string $value): void
    {
        if ($value === 'manual') {
            $this->source_control_account_id = '';
            $this->repository_selection = '';
            $this->availableRepositories = [];

            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
    }

    public function updatedSourceControlAccountId(string $value): void
    {
        $this->source_control_account_id = $value;
        $this->repository_selection = '';
        $this->onSourceControlAccountChanging();
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
        $this->onSourceControlAccountChanged();
    }

    public function updatedRepositorySelection(string $value): void
    {
        foreach ($this->availableRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }
            $this->git_repository_url = (string) $repository['url'];
            $this->git_branch = (string) ($repository['branch'] ?: 'main');
            $this->git_ref_kind = 'branch';
            $this->onRepositorySelected();

            return;
        }
    }

    public function updatedGitRepositoryUrl(): void
    {
        $this->git_repository_url = trim($this->git_repository_url);
        $this->repoScanState = 'idle';
        $this->scannedRepoMeta = [];
        $this->scannedBranches = [];

        // Notify the host first so it can reset stale ref-picker state before
        // the scan potentially overwrites git_branch / git_ref_kind.
        $this->onManualRepoUrlChanged();

        if ($this->git_repository_url !== '') {
            $this->scanPublicRepository();
        }
    }

    /**
     * Reload {@see $availableRepositories} for the selected account, and
     * auto-select the first repo when nothing is chosen yet.
     */
    protected function refreshRepositories(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        if ($this->source_control_account_id === '' || auth()->user() === null) {
            $this->availableRepositories = [];

            return;
        }

        $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id);
        $this->availableRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];

        if ($this->availableRepositories !== [] && $this->repository_selection === '') {
            $first = $this->availableRepositories[0];
            $this->repository_selection = (string) $first['url'];
            $this->git_repository_url = (string) $first['url'];
            $this->git_branch = (string) ($first['branch'] ?: 'main');
            $this->git_ref_kind = 'branch';
            $this->onRepositoryAutoselected();
        }
    }

    // -------------------------------------------------------------------------
    // Public-repo scanning (manual / paste-URL mode)
    // -------------------------------------------------------------------------

    /**
     * If the pasted URL looks like a GitHub / GitLab / Bitbucket URL, hit the
     * provider's public API to fetch repo metadata + branch list. Results land
     * in {@see $scannedRepoMeta}, {@see $scannedBranches}, and {@see $repoScanState}.
     * The default branch is auto-applied to {@see $git_branch}.
     *
     * Called from {@see updatedGitRepositoryUrl()} after the host hook fires,
     * so the hook may reset ref state without racing the scan's branch write.
     */
    private function scanPublicRepository(): void
    {
        $parsed = $this->parsePublicRepoUrl($this->git_repository_url);
        if ($parsed === null) {
            return; // not a recognised provider URL — leave state 'idle'
        }

        ['provider' => $provider, 'owner' => $owner, 'repo' => $repo] = $parsed;

        try {
            match ($provider) {
                'github' => $this->scanGitHubRepo($owner, $repo),
                'gitlab' => $this->scanGitLabRepo($owner, $repo),
                'bitbucket' => $this->scanBitbucketRepo($owner, $repo),
            };
        } catch (\Throwable) {
            $this->repoScanState = 'error';
        }
    }

    /**
     * Detect provider and extract owner + repo from HTTPS or SSH URLs.
     *
     * @return array{provider: string, owner: string, repo: string}|null
     */
    private function parsePublicRepoUrl(string $url): ?array
    {
        // Use ~ as delimiter so that # inside character classes isn't
        // misread as the closing delimiter by PHP's pattern parser.

        // GitHub HTTPS & SSH
        if (preg_match('~^https?://github\.com/([^/]+)/([^/.?#]+?)(?:\.git)?(?:[/?#].*)?$~i', $url, $m)) {
            return ['provider' => 'github', 'owner' => $m[1], 'repo' => $m[2]];
        }
        if (preg_match('~^git@github\.com:([^/]+)/([^/.?#]+?)(?:\.git)?$~i', $url, $m)) {
            return ['provider' => 'github', 'owner' => $m[1], 'repo' => $m[2]];
        }
        // GitLab HTTPS & SSH (supports subgroups in owner)
        if (preg_match('~^https?://gitlab\.com/(.+?)/([^/.?#]+?)(?:\.git)?(?:[/?#].*)?$~i', $url, $m)) {
            return ['provider' => 'gitlab', 'owner' => $m[1], 'repo' => $m[2]];
        }
        if (preg_match('~^git@gitlab\.com:(.+?)/([^/.?#]+?)(?:\.git)?$~i', $url, $m)) {
            return ['provider' => 'gitlab', 'owner' => $m[1], 'repo' => $m[2]];
        }
        // Bitbucket HTTPS & SSH
        if (preg_match('~^https?://bitbucket\.org/([^/]+)/([^/.?#]+?)(?:\.git)?(?:[/?#].*)?$~i', $url, $m)) {
            return ['provider' => 'bitbucket', 'owner' => $m[1], 'repo' => $m[2]];
        }
        if (preg_match('~^git@bitbucket\.org:([^/]+)/([^/.?#]+?)(?:\.git)?$~i', $url, $m)) {
            return ['provider' => 'bitbucket', 'owner' => $m[1], 'repo' => $m[2]];
        }

        return null;
    }

    private function scanGitHubRepo(string $owner, string $repo): void
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'dply',
        ];

        [$meta, $branches] = Http::pool(fn ($pool) => [
            $pool->withHeaders($headers)->timeout(6)->get("https://api.github.com/repos/{$owner}/{$repo}"),
            $pool->withHeaders($headers)->timeout(6)->get("https://api.github.com/repos/{$owner}/{$repo}/branches", ['per_page' => 100]),
        ]);

        if (! $meta->ok()) {
            $this->repoScanState = $meta->status() === 404 ? 'not_found' : 'error';

            return;
        }

        $data = $meta->json();
        $defaultBranch = (string) ($data['default_branch'] ?? 'main');

        $this->scannedRepoMeta = [
            'provider' => 'github',
            'name' => (string) ($data['full_name'] ?? "{$owner}/{$repo}"),
            'description' => (string) ($data['description'] ?? ''),
            'visibility' => (bool) ($data['private'] ?? false) ? 'private' : 'public',
            'default_branch' => $defaultBranch,
            'stars' => (int) ($data['stargazers_count'] ?? 0),
        ];

        $this->git_branch = $defaultBranch;
        $this->git_ref_kind = 'branch';

        if ($branches->ok()) {
            $this->scannedBranches = collect($branches->json())
                ->filter(fn ($b) => is_array($b) && ($b['name'] ?? '') !== '')
                ->map(fn ($b) => ['name' => (string) $b['name'], 'default' => (string) $b['name'] === $defaultBranch])
                ->values()
                ->all();
        }

        $this->repoScanState = 'found';
    }

    private function scanGitLabRepo(string $owner, string $repo): void
    {
        $encodedPath = urlencode("{$owner}/{$repo}");
        $headers = ['User-Agent' => 'dply'];

        [$meta, $branches] = Http::pool(fn ($pool) => [
            $pool->withHeaders($headers)->timeout(6)->get("https://gitlab.com/api/v4/projects/{$encodedPath}"),
            $pool->withHeaders($headers)->timeout(6)->get("https://gitlab.com/api/v4/projects/{$encodedPath}/repository/branches", ['per_page' => 100]),
        ]);

        if (! $meta->ok()) {
            $this->repoScanState = $meta->status() === 404 ? 'not_found' : 'error';

            return;
        }

        $data = $meta->json();
        $defaultBranch = (string) ($data['default_branch'] ?? 'main');

        $this->scannedRepoMeta = [
            'provider' => 'gitlab',
            'name' => (string) ($data['path_with_namespace'] ?? "{$owner}/{$repo}"),
            'description' => (string) ($data['description'] ?? ''),
            'visibility' => (string) ($data['visibility'] ?? 'public'),
            'default_branch' => $defaultBranch,
            'stars' => (int) ($data['star_count'] ?? 0),
        ];

        $this->git_branch = $defaultBranch;
        $this->git_ref_kind = 'branch';

        if ($branches->ok()) {
            $this->scannedBranches = collect($branches->json())
                ->filter(fn ($b) => is_array($b) && ($b['name'] ?? '') !== '')
                ->map(fn ($b) => ['name' => (string) $b['name'], 'default' => (string) $b['name'] === $defaultBranch])
                ->values()
                ->all();
        }

        $this->repoScanState = 'found';
    }

    private function scanBitbucketRepo(string $owner, string $repo): void
    {
        $headers = ['User-Agent' => 'dply'];

        [$meta, $branches] = Http::pool(fn ($pool) => [
            $pool->withHeaders($headers)->timeout(6)->get("https://api.bitbucket.org/2.0/repositories/{$owner}/{$repo}"),
            $pool->withHeaders($headers)->timeout(6)->get("https://api.bitbucket.org/2.0/repositories/{$owner}/{$repo}/refs/branches", ['pagelen' => 100]),
        ]);

        if (! $meta->ok()) {
            $this->repoScanState = $meta->status() === 404 ? 'not_found' : 'error';

            return;
        }

        $data = $meta->json();
        $defaultBranch = (string) (($data['mainbranch'] ?? [])['name'] ?? 'main');

        $this->scannedRepoMeta = [
            'provider' => 'bitbucket',
            'name' => (string) ($data['full_name'] ?? "{$owner}/{$repo}"),
            'description' => (string) ($data['description'] ?? ''),
            'visibility' => (bool) ($data['is_private'] ?? false) ? 'private' : 'public',
            'default_branch' => $defaultBranch,
            'stars' => 0,
        ];

        $this->git_branch = $defaultBranch;
        $this->git_ref_kind = 'branch';

        if ($branches->ok()) {
            $this->scannedBranches = collect(($branches->json())['values'] ?? [])
                ->filter(fn ($b) => is_array($b) && ($b['name'] ?? '') !== '')
                ->map(fn ($b) => ['name' => (string) $b['name'], 'default' => (string) $b['name'] === $defaultBranch])
                ->values()
                ->all();
        }

        $this->repoScanState = 'found';
    }

    // -------------------------------------------------------------------------
    // Host hooks
    // -------------------------------------------------------------------------

    /** Host hook: before the account list/selection is cleared (e.g. authorize). */
    protected function onSourceControlAccountChanging(): void {}

    /** Host hook: after the account changed + repos reloaded (e.g. persist + invalidate). */
    protected function onSourceControlAccountChanged(): void {}

    /** Host hook: after a repository row is picked. */
    protected function onRepositorySelected(): void {}

    /** Host hook: after a manual repository URL is typed. */
    protected function onManualRepoUrlChanged(): void {}

    /** Host hook: after the first repository is auto-selected on account load. */
    protected function onRepositoryAutoselected(): void {}
}
