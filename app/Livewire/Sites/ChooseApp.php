<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunComposerScaffoldJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\AppCatalog;
use App\Services\Sites\SiteProvisioner;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
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
    public Server $server;

    public Site $site;

    /**
     * Catalog tiles available for this server's host kind.
     *
     * @var list<array<string, mixed>>
     */
    public array $tiles = [];

    /** Currently selected tile key (empty until the user picks one). */
    public string $selected = '';

    /** Server-default PHP version, applied to PHP-shaped tiles. */
    public string $phpVersion = '';

    // --- Tile-specific config sub-form ---------------------------------

    /**
     * Repository source for import/preset/static tiles:
     *  - 'provider': pick from a connected git account's repositories
     *  - 'manual':   paste a repository URL
     * Defaults to 'provider' when the user has linked accounts.
     */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $git_repository_url = '';

    public string $git_branch = 'main';

    public string $scaffold_admin_email = '';

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

    public function mount(Server $server, Site $site, AppCatalog $catalog, ServerPhpManager $phpManager, SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(config('dply.choose_app_enabled'), 404);
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->isVmHost(), 404);

        Gate::authorize('view', $site);
        Gate::authorize('update', $server);

        // Only bare or explicitly-skipped sites may (re)choose an app. A site
        // with a real app installed is locked.
        abort_unless($site->canRechooseApp(), 404);

        $this->server = $server;
        $this->site = $site;
        $this->tiles = $catalog->forServer($server);

        if ($server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->phpVersion = (string) ($phpManager->siteCreationPhpData($server)['preselected_version'] ?? '');
        }

        $this->scaffold_admin_email = (string) (auth()->user()?->email ?? '');

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
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
    }

    public function updatedRepositorySelection(string $value): void
    {
        foreach ($this->availableRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }
            $this->git_repository_url = (string) $repository['url'];
            $this->git_branch = (string) ($repository['branch'] ?: 'main');

            return;
        }
    }

    private function refreshRepositories(SourceControlRepositoryBrowser $repositoryBrowser): void
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
        }
    }

    /**
     * Pick a tile. Resets any stale validation; the blade reveals the
     * tile-specific config fields once a tile is selected.
     */
    public function selectTile(string $key): void
    {
        if ($this->tileFor($key) === null) {
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
        abort_unless($this->site->canRechooseApp(), 404);

        $tile = $this->tileFor($this->selected);
        if ($tile === null) {
            $this->addError('selected', __('Choose an application to continue.'));

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

        return $this->redirect(route('sites.scaffold-journey', [
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

        $this->site->forceFill([
            'type' => $type,
            'runtime' => $runtime,
            'runtime_version' => $runtime === 'php' && $this->phpVersion !== '' ? $this->phpVersion : null,
            'document_root' => $this->documentRoot($tile),
            'git_repository_url' => $repoUrl,
            'git_branch' => trim($this->git_branch) !== '' ? trim($this->git_branch) : 'main',
            'status' => Site::STATUS_PENDING,
            'meta' => $this->mergedMeta([
                'choose_app' => array_merge($this->chooseAppMeta(), [
                    'chosen_kind' => (string) $tile['kind'],
                    'chosen_key' => (string) $tile['key'],
                    'chosen_at' => now()->toISOString(),
                    'skipped' => false,
                    'repo_source' => $this->repo_source,
                    'source_control_account_id' => $this->repo_source === 'provider'
                        ? ($this->source_control_account_id ?: null)
                        : null,
                ]),
            ]),
        ])->save();

        $this->seedDeployStepsAndProvision($tile, $runtime, $siteProvisioner);

        $this->auditChosen($tile, (string) $tile['kind']);

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
        app(SiteDeployPipelineManager::class)->seedRuntimeDefaults(
            $this->site,
            $runtime,
            $framework !== '' ? $framework : null,
        );

        $this->site->loadMissing(['server', 'domains']);
        $siteProvisioner->markQueued($this->site);
        ProvisionSiteJob::dispatch($this->site->id);
    }

    private function documentRoot(array $tile): string
    {
        $base = $this->site->repository_path !== null && $this->site->repository_path !== ''
            ? rtrim((string) $this->site->repository_path, '/')
            : '/var/www/'.$this->site->slug;

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
        return view('livewire.sites.choose-app');
    }
}
