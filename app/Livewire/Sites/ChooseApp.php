<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\PreflightSiteSetupJob;
use App\Modules\Scaffold\Jobs\RunComposerScaffoldJob;
use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Livewire\Concerns\Sites\PicksRepositoryRef;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\AppCatalog;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteFoundationProvisioner;
use App\Services\Sites\SiteProvisioner;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Post-creation "Choose an application" step for the choose-app flow
 * (config/dply.php `choose_app_enabled`). A bare VM site lands here in
 * STATUS_AWAITING_APP; the user picks a tile (Git repo / WordPress / Laravel
 * / Statamic / static / blank), fills the tile-specific config, and runs it.
 *
 * Pick -> configure -> run. Real installers (WordPress / Laravel) reuse the
 * existing scaffold pipelines; imports/presets/static reuse the standard
 * provisioner; "Blank / Skip" provisions a default page and stays
 * re-choosable. See docs/CHOOSE_APP_FLOW.md.
 */
#[Layout('layouts.app')]
class ChooseApp extends Component
{
    use ConfiguresGitRepository;
    use PicksRepositoryRef;

    public Server $server;

    public Site $site;

    /**
     * Catalog tiles available for this server's host kind.
     *
     * @var list<array<string, mixed>>
     */
    public array $tiles = [];

    /** True when the site was already a live, provisioned host before this app
     *  choice — captured at run() entry, before status is flipped to PENDING. */
    private bool $siteWasProvisioned = false;

    /** Currently selected tile key (empty until the user picks one). */
    #[Url(as: 'app', except: '')]
    public string $selected = '';

    /** Server-default PHP version, applied to PHP-shaped tiles. */
    public string $phpVersion = '';

    // --- Tile-specific config sub-form ---------------------------------
    // The Git-repository picker state (repo_source, source_control_account_id,
    // repository_selection, git_repository_url, git_branch, git_ref_kind,
    // linkedSourceControlAccounts, availableRepositories) + its updated* hooks
    // live in the ConfiguresGitRepository trait.

    public string $scaffold_admin_email = '';

    public function mount(Server $server, Site $site, AppCatalog $catalog, ServerPhpManager $phpManager, SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(config('dply.choose_app_enabled'), 404);
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->isVmHost(), 404);

        Gate::authorize('view', $site);
        Gate::authorize('update', $server);

        $this->server = $server;
        $this->site = $site;

        // Only bare or explicitly-skipped sites may (re)choose an app. A site
        // that already has an app installed has nothing to choose — but that must
        // never dead-end on a 404 (e.g. Back/refresh after connecting a repo, or a
        // stale link): gracefully send the operator to the live site instead, so
        // they can still get to it.
        if (! $site->canRechooseApp()) {
            $this->redirectRoute('sites.show', ['server' => $server->id, 'site' => $site->id], navigate: true);

            return;
        }

        $this->tiles = $catalog->forServer($server);

        if ($server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->phpVersion = (string) ($phpManager->siteCreationPhpData($server)['preselected_version'] ?? '');
        }

        $this->scaffold_admin_email = (string) (auth()->user()?->email ?? '');

        // Drop bogus values supplied by the URL — they would leave the picker
        // in a "tile selected but config invisible" state.
        if ($this->selected !== '' && $this->tileFor($this->selected) === null) {
            $this->selected = '';
        }

        // Pre-load connected git accounts so the repository tile can offer a
        // dropdown instead of forcing a pasted URL. Default to provider mode
        // when the user has at least one linked account.
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->repo_source = 'provider';
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->refreshRepositories($repositoryBrowser);
        }
    }

    /** Trait hook: after the account changes + repos reload. */
    protected function onSourceControlAccountChanged(): void
    {
        $this->clearRepoRefSelection();
        $this->syncRepoUrlToSite();
    }

    /** Trait hook: after a repository row is picked. */
    protected function onRepositorySelected(): void
    {
        $this->clearRepoRefSelection();
        $this->syncRepoUrlToSite();
    }

    /** Trait hook: after a manual URL is typed. */
    protected function onManualRepoUrlChanged(): void
    {
        $this->git_ref_kind = null;
        $this->clearRepoRefSelection();
        $this->syncRepoUrlToSite();
    }

    /** Trait hook: after the first repo is auto-selected on account load. */
    protected function onRepositoryAutoselected(): void
    {
        $this->clearRepoRefSelection();
        $this->syncRepoUrlToSite();
    }

    /**
     * Persist the in-progress repository URL onto $site so the ref-picker's
     * SourceControlRepositoryReader can resolve branches/tags/commits before
     * the site is fully provisioned. Safe because the site is in
     * STATUS_AWAITING_APP and nothing has been deployed yet.
     */
    private function syncRepoUrlToSite(): void
    {
        $url = $this->repo_source === 'provider'
            ? trim($this->repository_selection)
            : trim($this->git_repository_url);
        if ($url === '') {
            return;
        }

        $accountId = $this->repo_source === 'provider' && $this->source_control_account_id !== ''
            ? $this->source_control_account_id
            : null;

        $storedAccountId = (string) ($this->site->repositoryMeta()['git_source_control_account_id'] ?? '');
        $urlChanged = (string) $this->site->git_repository_url !== $url;
        $accountChanged = $storedAccountId !== (string) $accountId;
        if (! $urlChanged && ! $accountChanged) {
            return;
        }

        $this->site->git_repository_url = $url;
        $this->site->mergeRepositoryMeta(['git_source_control_account_id' => $accountId]);
        $this->site->save();
    }

    /**
     * Trait hook: copy the chosen ref into the form fields so the existing
     * save path (runProvisioned) doesn't need to know about the picker.
     */
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
     * Wrapper for the blade button: ensure the repo URL is on the site, then
     * delegate to the trait's picker-open action.
     */
    public function openRefPicker(): void
    {
        $this->syncRepoUrlToSite();
        if (trim((string) $this->site->git_repository_url) === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Choose a repository first.'));
            }

            return;
        }
        $this->openRepoRefPicker();
    }

    /**
     * Pick a tile. Resets any stale validation; the blade reveals the
     * tile-specific config fields once a tile is selected.
     */
    public function selectTile(string $key): void
    {
        $tile = $this->tileFor($key);
        if ($tile === null || ($tile['coming_soon'] ?? false)) {
            return;
        }

        $this->selected = $key;
        $this->resetErrorBag();
    }

    /**
     * Run the selected tile: validate its config, transition the bare site
     * into its real shape, and either dispatch a scaffold pipeline or queue
     * standard provisioning.
     */
    public function run(SiteProvisioner $siteProvisioner): mixed
    {
        Gate::authorize('update', $this->server);
        // If the site picked up an app between page load and submit (double-submit,
        // a concurrent install), don't 404 — just land them on the live site.
        if (! $this->site->canRechooseApp()) {
            return $this->redirectRoute('sites.show', ['server' => $this->server->id, 'site' => $this->site->id], navigate: true);
        }

        // Capture this BEFORE any forceFill flips status to PENDING. A
        // services-first site is already a live, provisioned host — installing
        // an app onto it must be "pull the repo + deploy", not a re-run of the
        // whole foundation/site-creation provisioning.
        $this->siteWasProvisioned = $this->site->isReadyForWorkspace();

        $tile = $this->tileFor($this->selected);
        if ($tile === null) {
            $this->addError('selected', __('Choose an application to continue.'));

            return null;
        }
        if ($tile['coming_soon'] ?? false) {
            $this->addError('selected', __('That installer is coming soon — pick a Git repository, static site, or start blank for now.'));

            return null;
        }

        return match ((string) $tile['kind']) {
            'scaffold' => $this->runScaffold($tile),
            'static' => $this->runProvisioned($tile, SiteType::Static, 'static', $siteProvisioner),
            'blank' => $this->runBlank($tile, $siteProvisioner),
            default => $this->runProvisioned($tile, SiteType::Php, 'php', $siteProvisioner), // import / preset
        };
    }

    /**
     * WordPress / Laravel: reuse the existing scaffold pipeline. Moves the
     * site into STATUS_SCAFFOLDING and dispatches the tile's pipeline job,
     * then hands off to the scaffold-journey UI.
     */
    private function runScaffold(array $tile): mixed
    {
        $needsAdminEmail = (bool) ($tile['needs_admin_email'] ?? false);
        if ($needsAdminEmail) {
            $this->validate([
                'scaffold_admin_email' => ['required', 'email', 'max:255'],
            ], attributes: ['scaffold_admin_email' => __('admin email')]);
        }

        // Resolve the worker: dedicated installers (WordPress / Laravel) carry
        // a pipeline_job; recipe-driven installers (Statamic / Symfony / Craft
        // / Drupal) run the generic Composer pipeline.
        $recipe = is_array($tile['recipe'] ?? null) ? $tile['recipe'] : null;
        $jobClass = $tile['pipeline_job'] ?? ($recipe !== null ? RunComposerScaffoldJob::class : null);
        if (! is_string($jobClass) || ! class_exists($jobClass)) {
            $this->addError('selected', __('This installer is not available on this dply install yet.'));

            return null;
        }

        $hostname = $this->site->domains()->where('is_primary', true)->value('hostname');

        $scaffoldMeta = [
            'framework' => (string) $tile['framework'],
            'requested_hostname' => $hostname ?: null,
            'requested_at' => now()->toISOString(),
            'requested_by_user_id' => auth()->id(),
        ];
        if ($needsAdminEmail) {
            $scaffoldMeta['admin_email'] = $this->scaffold_admin_email;
        }
        if ($recipe !== null) {
            $scaffoldMeta['recipe'] = $recipe;
        }

        $this->site->forceFill([
            'type' => SiteType::Php,
            'runtime' => 'php',
            'runtime_version' => $this->phpVersion !== '' ? $this->phpVersion : null,
            'document_root' => $this->documentRoot($tile),
            'status' => Site::STATUS_SCAFFOLDING,
            'meta' => $this->mergedMeta([
                'scaffold' => $scaffoldMeta,
                'choose_app' => array_merge($this->chooseAppMeta(), [
                    'chosen_kind' => 'scaffold',
                    'chosen_key' => (string) $tile['key'],
                    'chosen_at' => now()->toISOString(),
                    'skipped' => false,
                ]),
            ]),
        ])->save();

        $jobClass::dispatch($this->site->id);

        $this->auditChosen($tile, 'scaffold');

        // Land on the site workspace — the scaffold-install flow renders inside
        // the shell (Show) while STATUS_SCAFFOLDING keeps it pre-workspace.
        return $this->redirect(route('sites.show', [
            'server' => $this->server,
            'site' => $this->site,
        ]), navigate: true);
    }

    /**
     * Git import / framework preset / static: set the runtime shape, lay
     * down the default deploy steps, and queue standard provisioning. A repo
     * URL is required so the first deploy has something to pull.
     */
    private function runProvisioned(array $tile, SiteType $type, string $runtime, SiteProvisioner $siteProvisioner): mixed
    {
        // Validate by repo source: a connected provider picks from a dropdown,
        // manual takes a pasted URL.
        if ($this->repo_source === 'provider') {
            $this->validate([
                'source_control_account_id' => ['required', 'string', 'max:26'],
                'repository_selection' => ['required', 'string', 'max:500'],
                'git_branch' => ['required', 'string', 'max:120'],
            ], attributes: ['repository_selection' => __('repository')]);
            $repoUrl = trim($this->repository_selection);
        } else {
            $this->validate([
                'git_repository_url' => ['required', 'string', 'max:500'],
                'git_branch' => ['required', 'string', 'max:120'],
            ], attributes: ['git_repository_url' => __('repository URL')]);
            $repoUrl = trim($this->git_repository_url);
        }

        $refKind = in_array($this->git_ref_kind, ['branch', 'tag', 'commit'], true)
            ? $this->git_ref_kind
            : 'branch';

        $accountId = $this->repo_source === 'provider' && $this->source_control_account_id !== ''
            ? $this->source_control_account_id
            : null;
        $existingRepoMeta = $this->site->repositoryMeta();

        // Repo import/preset on a live VM site → run the post-connect SETUP
        // WIZARD instead of a blind first deploy: a pre-flight job clones +
        // scans the repo, then either auto-deploys (clean) or holds for env /
        // resources configuration. The site STAYS LIVE (splash still serving)
        // — no flip to PENDING — so the workspace remains reachable throughout.
        // Static sites carry no env/runtime, so they keep the direct deploy.
        $useSetupWizard = $type === SiteType::Php
            && $this->siteWasProvisioned
            && $this->server->isVmHost();

        $metaPayload = [
            'git_ref_kind' => $refKind,
            'repository' => array_merge($existingRepoMeta, [
                'git_source_control_account_id' => $accountId,
            ]),
            'choose_app' => array_merge($this->chooseAppMeta(), [
                'chosen_kind' => (string) $tile['kind'],
                'chosen_key' => (string) $tile['key'],
                'chosen_at' => now()->toISOString(),
                'skipped' => false,
                'repo_source' => $this->repo_source,
                'source_control_account_id' => $accountId,
            ]),
        ];
        if ($useSetupWizard) {
            $metaPayload['setup'] = ['state' => 'scanning', 'started_at' => now()->toISOString()];
        }

        $this->site->forceFill([
            'type' => $type,
            'runtime' => $runtime,
            'runtime_version' => $runtime === 'php' && $this->phpVersion !== '' ? $this->phpVersion : null,
            'document_root' => $this->documentRoot($tile),
            'git_repository_url' => $repoUrl,
            'git_branch' => trim($this->git_branch) !== '' ? trim($this->git_branch) : 'main',
            // Stay live for the wizard path; legacy/static path keeps PENDING.
            'status' => $useSetupWizard ? $this->site->status : Site::STATUS_PENDING,
            'meta' => $this->mergedMeta($metaPayload),
        ])->save();

        $this->auditChosen($tile, (string) $tile['kind']);

        if ($useSetupWizard) {
            PreflightSiteSetupJob::dispatch($this->site->id, (string) auth()->id());

            return $this->redirect(route('sites.repository', [$this->server, $this->site, 'repo_tab' => 'setup']), navigate: true);
        }

        $this->seedDeployStepsAndProvision($tile, $runtime, $siteProvisioner);

        return $this->redirect(route('sites.show', [$this->server, $this->site]), navigate: true);
    }

    /**
     * Blank / Skip: provision a default-page PHP site now, but keep it
     * re-choosable (meta.choose_app.skipped = true) so the user can install
     * a real application later from the same picker.
     */
    private function runBlank(array $tile, SiteProvisioner $siteProvisioner): mixed
    {
        $this->site->forceFill([
            'type' => SiteType::Php,
            'runtime' => 'php',
            'runtime_version' => $this->phpVersion !== '' ? $this->phpVersion : null,
            'document_root' => $this->documentRoot($tile),
            'status' => Site::STATUS_PENDING,
            'meta' => $this->mergedMeta([
                'choose_app' => array_merge($this->chooseAppMeta(), [
                    'chosen_kind' => 'blank',
                    'chosen_key' => (string) $tile['key'],
                    'chosen_at' => now()->toISOString(),
                    'skipped' => true,
                ]),
            ]),
        ])->save();

        $this->seedDeployStepsAndProvision($tile, 'php', $siteProvisioner);

        $this->auditChosen($tile, 'blank');

        return $this->redirect(route('sites.show', [$this->server, $this->site]), navigate: true);
    }

    private function seedDeployStepsAndProvision(array $tile, string $runtime, SiteProvisioner $siteProvisioner): void
    {
        $framework = (string) ($tile['framework'] ?? '');
        $site = $this->site->fresh() ?? $this->site;
        $hasRepo = is_string($site->git_repository_url) && trim((string) $site->git_repository_url) !== '';

        // Services-first: the site is already a live, provisioned host. Installing
        // an app onto it is strictly "pull the repo + deploy it" — NEVER a re-run
        // of the whole foundation/site-creation provisioning. A blank/no-repo
        // choice needs no deploy at all: the splash page already serves.
        if ($this->siteWasProvisioned) {
            if ($hasRepo) {
                app(SiteDeployPipelineManager::class)
                    ->seedRuntimeDefaults($site, $runtime, $framework !== '' ? $framework : null);
                app(SiteDeploySyncCoordinator::class)
                    ->dispatchManualForGroup($site->fresh());
            }

            return;
        }

        // Legacy path: the foundation isn't provisioned yet (pre-services-first
        // awaiting-app sites). Run the full bare-foundation sequence (seed
        // pipeline → markQueued → install-webserver-first-or-provision); the
        // repo's first deploy follows the foundation.
        (new SiteFoundationProvisioner($siteProvisioner))->provision($site, $runtime, $framework);
    }

    private function documentRoot(array $tile): string
    {
        $base = $this->site->repository_path !== null && $this->site->repository_path !== ''
            ? rtrim((string) $this->site->repository_path, '/')
            : $this->site->conventionalRepositoryPath();

        return $base.((string) ($tile['web_subdir'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function mergedMeta(array $patch): array
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        return array_replace($meta, $patch);
    }

    /**
     * @return array<string, mixed>
     */
    private function chooseAppMeta(): array
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $existing = $meta['choose_app'] ?? [];

        return is_array($existing) ? $existing : [];
    }

    private function auditChosen(array $tile, string $kind): void
    {
        if (! $this->server->organization) {
            return;
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            'site.app_chosen',
            $this->site,
            null,
            [
                'app_key' => (string) $tile['key'],
                'app_kind' => $kind,
                'framework' => (string) ($tile['framework'] ?? ''),
                'server_id' => (string) $this->server->id,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tileFor(string $key): ?array
    {
        foreach ($this->tiles as $tile) {
            if (($tile['key'] ?? null) === $key) {
                return $tile;
            }
        }

        return null;
    }

    public function render(): View
    {
        // Feed the shared site workspace sidebar the same vars the sibling
        // routed pages (Errors, Monitor, Files) provide, so this page renders
        // inside the full site wrapper with breadcrumb + nav context.
        $runtimeMode = $this->site->runtimeTargetMode();

        return view('livewire.sites.choose-app', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'general',
            'runtimeMode' => $runtimeMode,
            'openErrorCount' => 0,
            'runtimePublication' => [],
        ]);
    }
}
