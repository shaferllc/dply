<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Livewire\Concerns\Sites\PicksRepositoryRef;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SiteGitCommitsFetcher;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Services\SourceControl\SourceControlRepositoryReader;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * DEPLOY > Repository — read + manage the connected git repository for a
 * serverless workspace. The legacy `?section=repository` settings tab
 * was a config form; serverless operators don't ssh into the host, so
 * the page is recast as a small repo dashboard: overview (commits +
 * README), file browser, branch picker, and a connection tab that
 * subsumes the old form.
 *
 * Reads go through {@see SourceControlRepositoryReader} (cached). The
 * Connection tab's actions reuse existing services
 * ({@see SourceControlRepositoryBrowser} for repo lists,
 * {@see RepositoryWebhookProvisioner} for webhooks) rather than wiring
 * up new code paths.
 */
#[Layout('layouts.app')]
class Repository extends Component
{
    use ConfiguresGitRepository;
    use DispatchesToastNotifications;
    use PicksRepositoryRef;
    use RefreshesLinkedSourceControlAccounts;

    public Server $server;

    public Site $site;

    /** When true, suppress the page wrapper (breadcrumb / sidebar / header) so the
     * component renders cleanly inside another page (e.g. the Deployments Settings tab). */
    public bool $embedded = false;

    /** When set to a tab id, pin the component to that tab and hide the tablist. Used by
     * the Deployments page's "Commits" surface to render commits-only without the rest of
     * Repository's chrome. */
    public string $lockedTab = '';

    // Aliased to `repo_*` so this component plays nicely when embedded inside
    // the Deployments page (its own ?tab= owns the outer tab strip).
    #[Url(as: 'repo_tab', except: 'overview')]
    public string $tab = 'overview';

    /** Path being browsed under the Files tab. Empty string = repo root. */
    #[Url(as: 'repo_path', except: '')]
    public string $filesPath = '';

    /** File the operator clicked into in the Files tab. */
    public string $filesOpenFile = '';

    /** Override which ref the Files/Overview tabs read from — defaults to `Site::$git_branch`. */
    #[Url(as: 'repo_ref', except: '')]
    public string $branchOverride = '';

    /** Search box on the Branches tab. */
    public string $branchSearch = '';

    /** Filter box on the Commits tab (matches message / author / sha). */
    #[Url(as: 'repo_q', except: '')]
    public string $commitFilter = '';

    /** 1-based page for the Commits tab — pages through the full history. */
    #[Url(as: 'commits_page', except: 1)]
    public int $commitsPage = 1;

    /** Reset to the first page whenever the filter changes. */
    public function updatedCommitFilter(): void
    {
        $this->commitsPage = 1;
    }

    /** Step the Commits tab one page in either direction (never below 1). */
    public function changeCommitsPage(int $delta): void
    {
        $this->commitsPage = max(1, $this->commitsPage + $delta);
    }

    /**
     * Re-fetch the repository panels (commits / files / README) from the Git
     * provider. render() loads that data fresh on every pass, so simply
     * returning here triggers a Livewire re-render that re-runs the provider
     * API calls — giving the error states a reliable "Retry" affordance after a
     * transient provider 404/5xx or once repo access is fixed, without a full
     * page reload.
     */
    public function reloadRepository(): void
    {
        // No state to mutate — the re-render re-runs the fetches in render().
    }

    // Connection-tab repository picker state (repo_source, source_control_account_id,
    // repository_selection, git_repository_url, git_branch, git_ref_kind,
    // linkedSourceControlAccounts, availableRepositories) lives in the
    // ConfiguresGitRepository trait; the rich picker renders from those.

    public function mount(Server $server, Site $site, SourceControlRepositoryBrowser $browser): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        // Intentionally do NOT mirror $lockedTab into $tab here. $tab is
        // bound to ?repo_tab=… via the Url attribute; assigning lockedTab
        // to it would push that value into the browser URL, which other
        // embeds of this same component (e.g. the Repository top-level tab
        // on the Deployments page) then inherit, causing them to render the
        // locked partial too. activeTab() resolves the effective tab at
        // render time without touching the URL-bound property.

        $this->git_repository_url = (string) ($site->git_repository_url ?? '');
        $this->git_branch = (string) ($site->git_branch ?? '') !== '' ? (string) $site->git_branch : 'main';
        $this->source_control_account_id = (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');

        $storedKind = is_array($site->meta ?? null) ? (string) ($site->meta['git_ref_kind'] ?? '') : '';
        $this->git_ref_kind = in_array($storedKind, ['branch', 'tag', 'commit'], true) ? $storedKind : null;

        $this->primeRepositoryPicker($browser);
    }

    /**
     * Seed the rich picker: load the user's linked accounts and, when one is
     * wired, the repositories for it. Decide provider vs manual mode so an
     * existing connection is never hidden — provider mode only when the stored
     * URL actually matches a listed repo for the stored account (otherwise the
     * URL shows in the manual field). A never-connected site with linked
     * accounts defaults to the provider picker (auto-selects the first repo).
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
            // No repo yet — offer the provider picker.
            $this->repo_source = 'provider';
            if ($this->source_control_account_id === '') {
                $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            }
            $this->refreshRepositories($browser);

            return;
        }

        // Existing connection: pre-set the selection so refreshRepositories does
        // not auto-pick the first repo, then only stay in provider mode if the
        // stored URL is genuinely one of the account's repos.
        $this->repository_selection = $this->git_repository_url;
        if ($this->source_control_account_id !== '') {
            $this->refreshRepositories($browser);
        }
        $match = collect($this->availableRepositories)->firstWhere('url', $this->git_repository_url);
        if ($match !== null) {
            $this->repo_source = 'provider';
        } else {
            $this->repo_source = 'manual';
            $this->repository_selection = '';
        }
    }

    /** Trait hook: linked accounts refreshed after connecting a provider mid-flow. */
    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }
        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
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

    /**
     * Trait hook (before the account list/selection clears): authorize the edit.
     */
    protected function onSourceControlAccountChanging(): void
    {
        Gate::authorize('update', $this->site);
    }

    /**
     * Trait hook (after the account changes + repos reload): persist the choice
     * immediately so every read (commits / branches / files / README) resolves to
     * the operator's chosen identity right away, exactly like the setup wizard's
     * account selector. Without the persist, the pick only lands on "Save
     * connection" and reads silently fall back to the first available token for
     * the provider ({@see GitIdentityResolver::forUserProvider}) — the wrong one
     * when several tokens are linked, which 404s on private repos.
     */
    protected function onSourceControlAccountChanged(): void
    {
        $this->site->mergeRepositoryMeta([
            'git_source_control_account_id' => $this->source_control_account_id !== '' ? $this->source_control_account_id : null,
        ]);
        $this->site->save();

        // Branch/file/README reads are cached per-site; drop the cache so the
        // next render re-fetches with the newly-selected identity.
        app(SourceControlRepositoryReader::class)->invalidate($this->site);
    }

    /** Trait hook: a repo was picked / the manual URL changed — reset any chosen ref. */
    protected function onRepositorySelected(): void
    {
        $this->clearRepoRefSelection();
    }

    protected function onManualRepoUrlChanged(): void
    {
        $this->clearRepoRefSelection();
    }

    protected function onRepositoryAutoselected(): void
    {
        $this->clearRepoRefSelection();
    }

    /**
     * Switch the active sub-tab. Backed by a real action (rather than a bare
     * `$set('tab', …)`) so `wire:loading wire:target="selectTab"` can scope a
     * spinner to the switch — `$set` magic actions aren't reliably matchable
     * as loading targets. Guarded to the tabs the unlocked view actually
     * exposes; lockedTab embeds never render the tablist, so they can't reach
     * this.
     */
    public function selectTab(string $tab): void
    {
        if (! in_array($tab, self::UNLOCKED_TABS, true)) {
            return;
        }

        $this->tab = $tab;
    }

    /* ──────────── Files navigation ──────────── */

    public function navigateToPath(string $path): void
    {
        $this->filesPath = trim($path, '/');
        $this->filesOpenFile = '';
    }

    public function openFile(string $path): void
    {
        $this->filesOpenFile = trim($path, '/');
    }

    public function closeFile(): void
    {
        $this->filesOpenFile = '';
    }

    /* ──────────── Branches ──────────── */

    public function switchBranch(string $branch, SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        $branch = trim($branch);
        if ($branch === '') {
            $this->toastError(__('Pick a branch first.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['git_ref_kind'] = 'branch';
        $this->site->forceFill([
            'git_branch' => $branch,
            'meta' => $meta,
        ])->save();
        $reader->invalidate($this->site);
        $this->git_branch = $branch;
        $this->git_ref_kind = 'branch';
        $this->branchOverride = '';
        $this->toastSuccess(__('Deploy branch set to :branch.', ['branch' => $branch]));
    }

    /* ──────────── Connection tab actions ──────────── */

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

        \App\Jobs\PreflightSiteSetupJob::dispatch($site->id, (string) auth()->id());

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

    /* ──────────── Render ──────────── */

    public function render(): View
    {
        $user = auth()->user();
        $reader = app(SourceControlRepositoryReader::class);
        $commitsFetcher = app(SiteGitCommitsFetcher::class);
        $browser = app(SourceControlRepositoryBrowser::class);

        $branchInUse = $this->branchOverride !== ''
            ? $this->branchOverride
            : (string) ($this->site->git_branch ?: 'main');

        $payload = [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => __('App'),
            'resourcePlural' => __('apps'),
            'section' => 'repository',
            'laravel_tab' => 'commands',
            'routingTab' => 'domains',
            'branchInUse' => $branchInUse,
            'currentBranch' => (string) ($this->site->git_branch ?: 'main'),
            'currentRepositoryUrl' => (string) ($this->site->git_repository_url ?: ''),
            'providerKind' => (string) ($this->site->repositoryMeta()['git_provider_kind'] ?? ''),
            // Conditional "Set up" tab: only while the first-deploy setup wizard
            // owns this site (held for env/resources, scanning, or scan failure).
            // It disappears the moment the first deploy lands.
            'showSetupTab' => $this->site->isInFirstDeploySetup() || $this->site->needsFirstDeploySetup(),
        ];

        if ($payload['currentRepositoryUrl'] === '') {
            return view('livewire.sites.repository', $payload + $this->renderConnectionPayload($browser, $user));
        }

        $activeTab = $this->activeTab();
        $payload['activeTab'] = $activeTab;

        return view('livewire.sites.repository', match ($activeTab) {
            'files' => $payload + $this->renderFilesPayload($reader, $user, $branchInUse),
            'branches' => $payload + $this->renderBranchesPayload($reader, $user),
            'commits' => $payload + $this->renderCommitsPayload($commitsFetcher, $user, $branchInUse),
            'connection' => $payload + $this->renderConnectionPayload($browser, $user),
            // Webhook (Quick deploy) shares the same backing data as
            // Connection — connectionDeployHookUrl + connectionQuickDeploy
            // live on the Connection payload. We render only the webhook
            // card via lockedTab="webhook" on the embedded component.
            'webhook' => $payload + $this->renderConnectionPayload($browser, $user),
            // Danger zone (disconnect repo + start over) needs no remote reads —
            // just the base payload (server/site are public props on the view).
            'danger' => $payload,
            // Setup wizard tab embeds the SiteSetup component, which loads its
            // own data — the base payload is all the host view needs.
            'setup' => $payload,
            default => $payload + $this->renderOverviewPayload($reader, $commitsFetcher, $user, $branchInUse),
        });
    }

    /**
     * Tabs the user can actually reach via the visible sub-tab strip in
     * the unlocked Repository view. 'webhook' is intentionally NOT here —
     * it's only ever reachable through a locked embed.
     */
    private const UNLOCKED_TABS = ['overview', 'commits', 'files', 'branches', 'connection', 'danger', 'setup'];

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
