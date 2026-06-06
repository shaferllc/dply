<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Sites;

use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;

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
        $this->onManualRepoUrlChanged();
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
