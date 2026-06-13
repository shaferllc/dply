<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\PreflightSiteSetupJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Livewire\Concerns\Sites\PicksRepositoryRef;
use App\Livewire\Sites\Concerns\BuildsRepositoryView;
use App\Livewire\Sites\Concerns\ManagesRepositoryBrowsing;
use App\Livewire\Sites\Concerns\ManagesRepositoryConnection;
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
    use BuildsRepositoryView;
    use ConfiguresGitRepository;
    use DispatchesToastNotifications;
    use ManagesRepositoryBrowsing;
    use ManagesRepositoryConnection;
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

    /**
     * Which network-backed tab has had its remote data loaded. On the standalone
     * page every such tab paints a skeleton first (no provider call) and streams
     * its data in via `wire:init="loadTab(...)"`; until loadedTab matches the
     * active tab, render() skips the provider fetch. Empty on first paint so the
     * initial GET makes zero source-control API calls. Embedded/locked hosts
     * (the Deployments page) bypass this and render eagerly.
     */
    public string $loadedTab = '';

    /** Whether the Connection tab's repository list (a provider API call) has been
     * loaded. Deferred out of mount() so opening Overview never lists repos. */
    public bool $connectionReposPrimed = false;

    /** Whether we've checked (once) that the stored deploy branch actually exists
     * on the remote and healed it to the repo's default if not. Guards the
     * one-shot self-heal in {@see ensureDeployBranchResolved()}. */
    public bool $branchResolved = false;

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


    /* ──────────── Files navigation ──────────── */


    /* ──────────── Branches ──────────── */


    /* ──────────── Connection tab actions ──────────── */


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

        // Embedded/locked hosts (the Deployments page) render eagerly — they
        // don't get the standalone page's wire:init lazy shell, so any provider
        // data they need is primed here instead of after first paint.
        $lazyHost = ! $this->embedded && $this->lockedTab === '';

        if ($payload['currentRepositoryUrl'] === '') {
            if (! $lazyHost) {
                $this->primeConnectionRepositories();
            }

            return view('livewire.sites.repository', $payload + $this->renderConnectionPayload($browser, $user));
        }

        $activeTab = $this->activeTab();
        $payload['activeTab'] = $activeTab;

        if (! $lazyHost && ($activeTab === 'connection' || $activeTab === 'webhook')) {
            $this->primeConnectionRepositories();
        }

        // Lazy gate: on the standalone page a network-backed tab renders a
        // skeleton on first paint and streams its data via wire:init → loadTab().
        // Cheap, network-free tabs (setup / danger) never defer.
        $deferTab = $lazyHost
            && in_array($activeTab, self::LAZY_TABS, true)
            && $this->loadedTab !== $activeTab;
        $payload['tabDeferred'] = $deferTab;

        if ($deferTab) {
            return view('livewire.sites.repository', $payload);
        }

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
     * Tabs whose render does a provider/API read and therefore lazy-loads behind
     * a skeleton on the standalone page. 'setup' and 'danger' are excluded (no
     * remote reads); 'webhook' is included for the locked webhook embed.
     */
    private const LAZY_TABS = ['overview', 'commits', 'files', 'branches', 'connection', 'webhook'];


}
