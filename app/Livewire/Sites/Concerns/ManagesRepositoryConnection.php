<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\PreflightSiteSetupJob;
use App\Livewire\Sites\Commits;
use App\Livewire\Sites\Files;
use App\Models\Site;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Modules\SourceControl\Services\SourceControlRepositoryReader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesRepositoryConnection
{


    /**
     * One-shot deploy-branch self-heal. A site can carry a guessed default
     * ("main") that doesn't exist on the remote (whose default is, say,
     * "master") — every branch-scoped read then 404s and pays a
     * fallback-lookup-retry tax. Here we list the remote's branches once (a
     * cached read, shared with the Branches tab) and, if the configured branch
     * isn't among them, persist the repo's real default so ALL subsequent reads
     * (commits, files, README, deploys) resolve correctly.
     *
     * Scoped to never-deployed sites so we never override a branch the operator
     * deliberately set or one a live deployment already depends on.
     */
    private function ensureDeployBranchResolved(): void
    {
        if ($this->branchResolved) {
            return;
        }
        $this->branchResolved = true;

        $user = auth()->user();
        if ($user === null
            || (string) $this->site->git_repository_url === ''
            || $this->site->last_deploy_at !== null) {
            return;
        }

        $reader = app(SourceControlRepositoryReader::class);
        $result = $reader->branches($this->site, $user);
        if (! ($result['ok'] ?? false) || $result['branches'] === []) {
            return;
        }

        $names = array_map(static fn (array $b): string => (string) ($b['name'] ?? ''), $result['branches']);
        $configured = (string) ($this->site->git_branch ?: 'main');
        if (in_array($configured, $names, true)) {
            return; // configured branch exists — nothing to heal
        }

        $default = collect($result['branches'])->firstWhere('is_default', true);
        $target = (string) ($default['name'] ?? $names[0] ?? '');
        if ($target === '' || strcasecmp($target, $configured) === 0) {
            return;
        }

        $this->site->forceFill(['git_branch' => $target])->save();
        $reader->invalidate($this->site);
        $this->git_branch = $target;
    }

    /**
     * Load the Connection tab's repository dropdown (a provider API call —
     * `GET /user/repos` & friends). Deferred out of mount() so it never runs on
     * the Overview/Commits/Files/Branches tabs, which don't show the picker.
     * Idempotent: the network call fires at most once per component lifecycle.
     */
    public function primeConnectionRepositories(): void
    {
        if ($this->connectionReposPrimed) {
            return;
        }
        $this->connectionReposPrimed = true;

        if ($this->source_control_account_id === '') {
            return;
        }

        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));

        // Existing connection: now that the real list is in hand, fall back to the
        // manual URL field if the stored repo URL isn't actually one of the
        // account's repos (the verification mount() used to do eagerly).
        if ($this->repo_source === 'provider' && $this->git_repository_url !== ''
            && collect($this->availableRepositories)->firstWhere('url', $this->git_repository_url) === null) {
            $this->repo_source = 'manual';
            $this->repository_selection = '';
        }
    }

    /**
     * Seed the rich picker WITHOUT any provider API call. Linked accounts are a
     * local DB read (safe in mount); the repository list — `GET /user/repos` &
     * friends, the slowest call on the page — is deferred to
     * {@see primeConnectionRepositories()} and only loads when the Connection
     * tab opens. Provider-vs-manual mode is decided here from the stored
     * provider kind so an existing connection is never hidden; the exact
     * "is the URL one of the account's repos?" verification also moves to the
     * lazy primer.
     */
    private function primeRepositoryPicker(SourceControlRepositoryBrowser $browser): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->repo_source = 'manual';

            return;
        }

        $this->linkedSourceControlAccounts = $browser->accountsForUser($user);
        if ($this->linkedSourceControlAccounts === []) {
            $this->repo_source = 'manual';

            return;
        }

        if ($this->git_repository_url === '') {
            // Never connected — default to the provider picker; the repo list
            // (and first-repo auto-select) loads lazily on the Connection tab.
            $this->repo_source = 'provider';
            if ($this->source_control_account_id === '') {
                $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            }

            return;
        }

        // Existing connection: pre-set the selection and pick provider vs manual
        // from the stored provider kind (no network). primeConnectionRepositories()
        // refines this to 'manual' later if the URL isn't actually in the list.
        $this->repository_selection = $this->git_repository_url;
        $storedProvider = (string) ($this->site->repositoryMeta()['git_provider_kind'] ?? '');
        if ($storedProvider === '') {
            $storedProvider = $this->detectProviderKind($this->git_repository_url);
        }
        $accountProviders = array_map(
            static fn (array $account): string => (string) ($account['provider'] ?? ''),
            $this->linkedSourceControlAccounts,
        );
        $hasMatchingAccount = $this->source_control_account_id !== '' && in_array($storedProvider, $accountProviders, true);

        $this->repo_source = $hasMatchingAccount ? 'provider' : 'manual';
        if ($this->repo_source === 'manual') {
            $this->repository_selection = '';
        }
    }

    /**
     * If the user edits the URL on the connection form and then clicks
     * "Change ref", sync the in-progress URL to the site so the picker's
     * reader can resolve refs against the correct remote. We intentionally
     * mutate $site->git_repository_url only — the branch field stays whatever
     * was last persisted until the user saves the form.
     */
    public function openConnectionRefPicker(): void
    {
        Gate::authorize('update', $this->site);

        $url = trim($this->git_repository_url);
        if ($url === '') {
            $this->toastError(__('Enter a repository URL first.'));

            return;
        }
        if ((string) $this->site->git_repository_url !== $url) {
            $this->site->forceFill(['git_repository_url' => $url])->save();
            app(SourceControlRepositoryReader::class)->invalidate($this->site);
        }

        $this->openRepoRefPicker();
    }

    public function onRepoRefSelected(): void
    {
        $label = (string) ($this->repo_ref_selected_label ?? '');
        if ($label === '') {
            return;
        }
        $this->git_branch = $label;
        $this->git_ref_kind = $this->repo_ref_selected_kind;
    }

    public function saveConnection(SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        // In provider mode the picker mirrors the chosen repo into
        // git_repository_url, so validating that single field covers both modes.
        $this->validate([
            'git_repository_url' => 'required|string|max:500',
            'git_branch' => 'required|string|max:120',
            'source_control_account_id' => 'nullable|string|max:26',
        ]);

        $url = $this->repo_source === 'provider' && trim($this->repository_selection) !== ''
            ? trim($this->repository_selection)
            : trim($this->git_repository_url);
        $branch = trim($this->git_branch) !== '' ? trim($this->git_branch) : 'main';
        $refKind = in_array($this->git_ref_kind, ['branch', 'tag', 'commit'], true)
            ? $this->git_ref_kind
            : 'branch';

        $this->site->mergeRepositoryMeta([
            'git_source_control_account_id' => $this->source_control_account_id !== '' ? $this->source_control_account_id : null,
            'git_provider_kind' => $this->detectProviderKind($url),
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['git_ref_kind'] = $refKind;
        $this->site->fill([
            'git_repository_url' => $url,
            'git_branch' => $branch,
            'meta' => $meta,
        ])->save();
        $reader->invalidate($this->site);

        if ($this->startFirstDeploySetupIfEligible()) {
            return;
        }

        $this->toastSuccess(__('Repository connection saved.'));
    }

    /**
     * If this is the FIRST repo connected to a never-deployed, provisioned VM
     * site, kick the post-connect setup wizard (pre-flight scan → env / resources
     * → deploy) exactly like the choose-app picker — instead of leaving the repo
     * connected with nothing else done. Already-deployed sites (switching repos)
     * and non-VM hosts skip this and keep their existing behaviour.
     */
    private function startFirstDeploySetupIfEligible(): bool
    {
        $site = $this->site->fresh() ?? $this->site;

        if (trim((string) $site->git_repository_url) === '' || $site->last_deploy_at !== null) {
            return false;
        }
        if (! $this->server->isVmHost() || ! $site->isReadyForWorkspace()) {
            return false;
        }
        if ($site->isInFirstDeploySetup()) {
            return false; // a scan is already in flight — don't double-dispatch
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['setup'] = ['state' => 'scanning', 'started_at' => now()->toIso8601String()];
        $site->forceFill(['meta' => $meta])->save();

        PreflightSiteSetupJob::dispatch($site->id, (string) auth()->id());

        $this->redirect(route('sites.repository', [$this->server, $site, 'repo_tab' => 'setup']), navigate: true);

        return true;
    }

    /**
     * Uninstall the connected repository and start the site over: clear the repo
     * fields locally, mark it re-choosable, then queue {@see ResetSiteToBlankJob}
     * to wipe the deployed code on the server and restore the splash page. The
     * site shell (server, domains, testing URL, certificates) is kept — only the
     * application is removed — so the operator can connect a different repo or
     * pick a new app from a clean slate.
     */
    public function disconnectAndStartOver(): void
    {
        Gate::authorize('update', $this->site);

        $site = $this->site;
        $meta = is_array($site->meta) ? $site->meta : [];
        foreach (['git_ref_kind', 'git_source_control_account_id', 'git_provider_kind', 'scaffold'] as $key) {
            unset($meta[$key]);
        }
        // Re-open the app picker for this site (services-first "skipped" sentinel
        // makes Site::canRechooseApp() return true).
        $meta['choose_app'] = [
            'skipped' => true,
            'reset_at' => now()->toIso8601String(),
            'reset_by_user_id' => auth()->id(),
        ];

        $site->forceFill([
            'git_repository_url' => '',
            'git_branch' => 'main',
            'last_deploy_at' => null,
            'meta' => $meta,
        ])->save();

        \App\Jobs\ResetSiteToBlankJob::dispatch((string) $site->id);

        $this->toastSuccess(__('Repository disconnected — wiping the deployed app and resetting to a blank splash page.'));

        $this->redirect(route('sites.show', ['server' => $this->server, 'site' => $site]), navigate: true);
    }

    public function enableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        // We already know the provider from the repository URL, so the operator
        // shouldn't have to re-pick an account just to register the push hook.
        // Resolve the identity exactly the way reads do: honour an explicitly
        // wired account, otherwise fall back to the connection this repo already
        // uses for that provider (forSite → best-available identity).
        $provider = $this->detectProviderKind((string) ($this->site->git_repository_url ?? ''));
        if (! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            $this->toastError(__('Quick deploy needs a GitHub, GitLab, or Bitbucket repository.'));

            return;
        }

        $resolver = app(GitIdentityResolver::class);
        $account = $this->source_control_account_id !== ''
            ? $resolver->forId(auth()->user(), $this->source_control_account_id)
            : null;
        $account ??= $resolver->forSite($this->site, auth()->user(), $provider);

        if ($account === null) {
            $this->toastError(__('Link a :provider account before enabling quick deploy.', ['provider' => ucfirst($provider)]));

            return;
        }

        // The provisioner reads the provider kind + backing account from stored
        // meta, not the live URL. Sites created outside the connection form (e.g.
        // serverless workers) can carry a stale 'custom' kind, so sync what we
        // just resolved into meta and persist it before the provisioner reloads
        // the site via ->fresh() — otherwise the patch is dropped.
        $patch = ['git_provider_kind' => $provider];
        if ((string) ($this->site->repositoryMeta()['git_source_control_account_id'] ?? '') === '') {
            $patch['git_source_control_account_id'] = $account->id();
            $this->source_control_account_id = (string) $account->id();
        }
        $this->site->mergeRepositoryMeta($patch);
        $this->site->save();

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            $this->toastError((string) ($result['message'] ?? __('Could not enable quick deploy.')));

            return;
        }

        $this->site->refresh();
        $this->toastSuccess((string) ($result['message'] ?? __('Quick deploy enabled.')));
    }

    public function disableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $provisioner->disable($this->site->fresh());
        $this->site->refresh();
        $this->toastSuccess(__('Quick deploy disabled.'));
    }

    public function regenerateWebhookSecret(RepositoryWebhookProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $plain = Str::random(48);
        $this->site->webhook_secret = $plain;
        $this->site->save();
        $provisioner->syncProviderHookSecret($this->site->fresh());
        $this->toastSuccess(__('Webhook secret rotated.'));
    }
}
