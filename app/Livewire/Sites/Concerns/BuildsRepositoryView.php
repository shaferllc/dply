<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Livewire\Sites\Commits;
use App\Livewire\Sites\SiteSetup;
use App\Models\Server;
use App\Models\SiteDeployment;
use App\Modules\SourceControl\Services\SiteGitCommitsFetcher;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\SourceControl\Services\SourceControlRepositoryReader;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsRepositoryView
{


    /**
     * Mark a network-backed tab's data as ready to load. Fired by the panel's
     * `wire:init` once its skeleton is on screen, so the provider calls happen
     * after first paint rather than blocking it. The Connection/Webhook tabs
     * also need the repository list, so prime it here in the same round-trip.
     */
    public function loadTab(string $tab): void
    {
        if (! in_array($tab, self::LAZY_TABS, true)) {
            return;
        }

        // Heal a stale deploy branch before this tab's reads run, so the
        // commits / files / README reads in this same round-trip use a branch
        // that actually exists (no 404 → fallback dance on every tab).
        if (in_array($tab, ['overview', 'commits', 'files', 'branches'], true)) {
            $this->ensureDeployBranchResolved();
        }

        $this->loadedTab = $tab;

        if ($tab === 'connection' || $tab === 'webhook') {
            $this->primeConnectionRepositories();
        }
    }

    /**
     * Switch the active sub-tab. Backed by a real action (rather than a bare
     * `$set('tab', …)`) so `wire:loading wire:target="selectTab"` can scope the
     * skeleton placeholder to the switch — `$set` magic actions aren't reliably
     * matchable as loading targets. Guarded to the tabs the unlocked view
     * actually exposes; lockedTab embeds never render the tablist, so they
     * can't reach this.
     *
     * Loads the new tab's data in THIS same round-trip (via loadTab) instead of
     * rendering a skeleton and waiting on a second wire:init request — one hop,
     * not two. Reads are cached, so flipping back to an already-visited tab is a
     * cache hit; the wire:loading skeleton still gives instant feedback while
     * the request is in flight.
     */
    public function selectTab(string $tab): void
    {
        if (! in_array($tab, self::UNLOCKED_TABS, true)) {
            return;
        }

        $this->tab = $tab;
        $this->loadTab($tab);
    }

    /**
     * The tab that should actually render. When the component is embedded
     * with a `lockedTab` prop, that wins over the URL-bound $tab; otherwise
     * fall back to the URL state — but only if it's a tab the unlocked
     * view actually supports. A stale ?repo_tab=webhook in the URL (left
     * over from an earlier build that mirrored lockedTab into $tab) would
     * otherwise cause the Repository top-level tab to render the webhook
     * partial because the URL-restored property says so. Falling back to
     * 'overview' for unrecognised values is the safe default.
     */
    private function activeTab(): string
    {
        if ($this->lockedTab !== '') {
            return $this->lockedTab;
        }

        $tab = in_array($this->tab, self::UNLOCKED_TABS, true) ? $this->tab : 'overview';

        // A stale ?repo_tab=setup left over after the site has LEFT first-deploy
        // setup (scan cleared / already deployed) would embed the SiteSetup
        // component — whose mount() redirects, and a child component redirecting
        // during mount crashes Livewire ("Redirector could not be converted to
        // int"). The Set-up tab only exists while setup is active, so fall back
        // to overview otherwise.
        if ($tab === 'setup' && ! ($this->site->isInFirstDeploySetup() || $this->site->needsFirstDeploySetup())) {
            return 'overview';
        }

        return $tab;
    }

    /**
     * Full commit history for the Commits tab (merged in from the former
     * standalone Commits page). Server-side filters the fetched window by
     * message / author / sha and flags the last successfully-deployed sha.
     *
     * @return array{commitsResult: array<string, mixed>, commitsFiltered: list<array<string, mixed>>, lastDeployedSha: ?string}
     */
    private function renderCommitsPayload(SiteGitCommitsFetcher $fetcher, $user, string $branch): array
    {
        $result = $fetcher->fetch($this->site, $user, 40, $branch, $this->commitsPage);

        $commits = $result['commits'];
        $filter = trim($this->commitFilter);
        if ($filter !== '') {
            $needle = mb_strtolower($filter);
            $commits = array_values(array_filter($commits, function (array $c) use ($needle): bool {
                return str_contains(mb_strtolower((string) $c['message']), $needle)
                    || str_contains(mb_strtolower((string) $c['author_name']), $needle)
                    || str_contains(mb_strtolower((string) $c['sha']), $needle)
                    || str_contains(mb_strtolower((string) $c['short_sha']), $needle);
            }));
        }

        $lastDeploy = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->whereNotNull('git_sha')
            ->orderByDesc('finished_at')
            ->first();

        return [
            'commitsResult' => $result,
            'commitsFiltered' => $commits,
            'lastDeployedSha' => $lastDeploy?->git_sha,
        ];
    }

    private function renderOverviewPayload(SourceControlRepositoryReader $reader, SiteGitCommitsFetcher $fetcher, $user, string $branch): array
    {
        $commits = $fetcher->fetch($this->site, $user, 5, $branch);
        $readme = $user !== null ? $reader->readme($this->site, $user, $branch) : null;

        return [
            'overviewCommits' => $commits,
            'overviewReadme' => $readme,
        ];
    }

    private function renderFilesPayload(SourceControlRepositoryReader $reader, $user, string $branch): array
    {
        if ($user === null) {
            return ['filesTree' => ['ok' => false, 'entries' => [], 'error' => __('Sign in to browse files.'), 'provider' => null, 'path' => $this->filesPath, 'branch' => $branch], 'filesView' => null];
        }

        $tree = $reader->tree($this->site, $user, $branch, $this->filesPath);
        $fileView = null;
        if ($this->filesOpenFile !== '') {
            $fileView = $reader->file($this->site, $user, $branch, $this->filesOpenFile);
        }

        return [
            'filesTree' => $tree,
            'filesView' => $fileView,
            'filesBreadcrumb' => $this->buildBreadcrumb($this->filesPath),
        ];
    }

    private function renderBranchesPayload(SourceControlRepositoryReader $reader, $user): array
    {
        if ($user === null) {
            return ['branchesResult' => ['ok' => false, 'branches' => [], 'error' => __('Sign in to list branches.'), 'provider' => null], 'branchesFiltered' => []];
        }

        $result = $reader->branches($this->site, $user);
        $filtered = $result['branches'];
        if ($this->branchSearch !== '') {
            $needle = strtolower($this->branchSearch);
            $filtered = array_values(array_filter($result['branches'], fn (array $b) => str_contains(strtolower($b['name']), $needle)));
        }

        return [
            'branchesResult' => $result,
            'branchesFiltered' => $filtered,
        ];
    }

    private function renderConnectionPayload(SourceControlRepositoryBrowser $browser, $user): array
    {
        // The rich picker (account select + repository dropdown) is driven by the
        // ConfiguresGitRepository trait's public props ($linkedSourceControlAccounts,
        // $availableRepositories), not this payload. The old "Repositories on this
        // account" library card + its swap modal were removed; only the quick-deploy
        // / webhook keys remain (the locked webhook embed reuses them).
        return [
            'connectionQuickDeploy' => (bool) ($this->site->repositoryMeta()['quick_deploy_enabled'] ?? ($this->site->meta['quick_deploy_enabled'] ?? false)),
            'connectionDeployHookUrl' => method_exists($this->site, 'deployHookUrl') ? $this->site->deployHookUrl() : null,
        ];
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function buildBreadcrumb(string $path): array
    {
        $crumbs = [['name' => __('Repository root'), 'path' => '']];
        $accum = '';
        foreach (array_filter(explode('/', $path)) as $segment) {
            $accum = $accum === '' ? $segment : $accum.'/'.$segment;
            $crumbs[] = ['name' => $segment, 'path' => $accum];
        }

        return $crumbs;
    }

    private function detectProviderKind(string $url): string
    {
        $url = strtolower($url);
        if (str_contains($url, 'github.com')) {
            return 'github';
        }
        if (str_contains($url, 'gitlab')) {
            return 'gitlab';
        }
        if (str_contains($url, 'bitbucket.org')) {
            return 'bitbucket';
        }

        return 'custom';
    }
}
