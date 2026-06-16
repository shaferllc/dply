<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunServerConfigOpJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\ClonesServer;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerConfigRevisions;
use App\Livewire\Servers\Concerns\ManagesServerLogo;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\RemoteServerConfigService;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerConfigFileEditor;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\WebserverWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified server configuration editor — allowlisted paths across webserver,
 * PHP, Redis/DB, system, and supervisor with CodeMirror editing.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceConfiguration extends Component
{
    use ClonesServer;
    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerConfigRevisions;
    use ManagesServerLogo;
    use RendersWorkspacePlaceholder;

    #[Url(as: 'file', except: null, history: true)]
    public ?string $config_selected_path = null;

    public string $config_contents = '';

    public ?string $config_validate_output = null;

    public ?bool $config_validate_ok = null;

    public bool $config_truncated_on_load = false;

    public bool $config_file_loaded = false;

    public bool $config_loaded_from_cache = false;

    public ?string $config_content_cached_at = null;

    public ?string $config_last_backup = null;

    /** @var array<int, array{path: string, mtime: int, size: int}> */
    public array $config_backups = [];

    /** @var array<string, string> */
    public array $config_drafts = [];

    #[Url(as: 'scope', except: '')]
    public string $config_scope = '';

    #[Url(as: 'q', except: '')]
    public string $config_search = '';

    /** When set to `webserver`, show a back link to the webserver workspace. */
    #[Url(as: 'from', except: '')]
    public string $config_from = '';

    /** Webserver engine sub-tab to restore when using the back link. */
    #[Url(as: 'return_sub', except: '')]
    public string $config_return_sub = '';

    public ?string $pending_load_console_id = null;

    public ?string $pending_load_path = null;

    /**
     * Allowlisted config files grouped for the sidebar picker. Populated lazily
     * via {@see loadConfigCatalog()} so the initial page render is not gated
     * on many sequential SSH round-trips.
     *
     * @var array<string, array{label: string, files: list<array{path: string, label: string, size: int, mtime: int|null, group: string, engine?: string}>}>
     */
    public array $groupedConfigFiles = [];

    public bool $configCatalogLoaded = false;

    public bool $configCatalogLoading = false;

    public ?string $configCatalogError = null;

    private bool $configRestoredFromUrl = false;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->tryHydrateSelectedFileFromCacheOnMount();
    }

    public function configFileContentLoading(): bool
    {
        return $this->config_selected_path !== null && ! $this->config_file_loaded;
    }

    public function reloadSelectedConfigFile(): void
    {
        if ($this->config_selected_path === null) {
            return;
        }

        $path = (string) $this->config_selected_path;
        RunServerConfigOpJob::forgetFileContentCache((string) $this->server->id, $path);
        unset($this->config_drafts[$path]);
        $this->markPathCachedInCatalog($path, false);
        $this->config_file_loaded = false;
        $this->config_loaded_from_cache = false;
        $this->config_content_cached_at = null;
        $this->loadConfigFile($path, forceRefresh: true);
    }

    public function updatedConfigSearch(): void
    {
        $this->reloadConfigCatalog();
    }

    public function setConfigScope(string $scope): void
    {
        $this->config_scope = $scope;
        $this->reloadConfigCatalog();
    }

    public function clearConfigScope(): void
    {
        $this->config_scope = '';
        $this->reloadConfigCatalog();
    }

    /**
     * Discover allowlisted config files over SSH. Runs once after first paint
     * via wire:init, and again when scope/search filters change.
     */
    public function loadConfigCatalog(): void
    {
        if ($this->configCatalogLoading) {
            return;
        }

        if (! $this->serverOpsReady()) {
            $this->groupedConfigFiles = [];
            $this->configCatalogLoaded = true;
            $this->configCatalogError = null;

            return;
        }

        $this->configCatalogLoading = true;
        $this->configCatalogError = null;

        try {
            $catalog = app(ServerConfigFileCatalog::class);
            $scope = $this->config_scope !== '' ? $this->config_scope : null;
            $search = $this->config_search !== '' ? $this->config_search : null;

            $this->groupedConfigFiles = Cache::remember(
                $this->configCatalogCacheKey($scope, $search),
                10,
                fn () => $catalog->groupedFiles($this->server, $scope, $search),
            );
            $this->refreshCachedFlagsOnCatalog();
        } catch (\Throwable) {
            $this->groupedConfigFiles = [];
            $this->configCatalogError = (string) __('Could not discover config files — confirm the server is reachable.');
        } finally {
            $this->configCatalogLoading = false;
            $this->configCatalogLoaded = true;
            $this->maybeRestoreConfigFileFromUrl();
        }
    }

    public function loadConfigFile(string $path, bool $forceRefresh = false): void
    {
        if (! $this->guardConfigAction(allowReadOnly: true)) {
            return;
        }

        try {
            $this->assertCatalogPath($path);
        } catch (\InvalidArgumentException) {
            $this->toastError(__('Path not allowed.'));

            return;
        }

        if ($this->config_selected_path !== null && $this->config_selected_path !== $path) {
            $this->stashConfigDraft((string) $this->config_selected_path, $this->config_contents);
        }

        $this->config_selected_path = $path;

        if (! $forceRefresh && isset($this->config_drafts[$path])) {
            $this->applyLoadedConfigFile($path, $this->config_drafts[$path], truncated: false, fromCache: false);

            return;
        }

        if (! $forceRefresh && $this->hydrateConfigFileFromCache($path)) {
            return;
        }

        // Straight, synchronous SSH pull — no queue, no console banner, no poll.
        // Both services read over a single direct SSH connection (capped under
        // the request limit), so the file lands on this request. The queue+poll
        // round-trip was the source of the "doesn't load" / status:null behaviour.
        $engine = $this->resolvedConfigEngineForPath($path);
        try {
            $result = $engine !== null
                ? app(RemoteWebserverConfigService::class)->read($this->server, $engine, $path)
                : app(RemoteServerConfigService::class)->read($this->server, $path);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not read :path: :msg', ['path' => basename($path), 'msg' => $e->getMessage()]));
            $this->config_selected_path = null;
            $this->config_file_loaded = false;

            return;
        }

        $contents = (string) ($result['contents'] ?? '');
        $this->applyLoadedConfigFile(
            $path,
            $contents,
            truncated: (bool) ($result['truncated'] ?? false),
            fromCache: false,
        );

        // Keep the per-path content cache warm so a later re-open is instant.
        Cache::put(
            RunServerConfigOpJob::fileContentCacheKey((string) $this->server->id, $path),
            ['contents' => $contents, 'truncated' => (bool) ($result['truncated'] ?? false), 'cached_at' => now()->toIso8601String()],
            now()->addMinutes(10),
        );
        $this->refreshCachedFlagsOnCatalog();
    }

    public function saveConfigFile(): void
    {
        $this->openConfigSaveConfirm();
    }

    public function confirmConfigSave(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            $this->toastError(__('Refusing to save: this file is too large for the editor and was loaded truncated.'));

            return;
        }
        if ($this->config_contents === $this->config_original_contents) {
            $this->toastError(__('No changes to save.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $engine = $this->resolvedConfigEngineForPath($path);

        if ($this->configRevisionsEnabled()) {
            app(ServerConfigFileEditor::class)->ensureBaseline(
                $this->server,
                $path,
                $this->config_original_contents,
                auth()->user(),
                $engine,
            );
        }

        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Save config: :path', ['path' => basename($path)]),
        );
        $this->pending_write_console_id = $consoleId;

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $path,
            $this->config_contents,
            '',
            auth()->id(),
            true,
            null,
            $engine,
        );

        $this->closeConfigSaveDiff();
        $this->toastSuccess(__('Save queued — progress shows in the banner above.'));
    }

    public function validateConfigBuffer(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            $this->toastError(__('Buffer is truncated — validation would chop the file.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Validate config buffer: :path', ['path' => basename($path)]),
        );
        $this->pending_validate_console_id = $consoleId;

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'validate',
            $path,
            $this->config_contents,
            engine: $this->resolvedConfigEngineForPath($path),
        );

        $this->toastSuccess(__('Validation queued — progress shows in the banner above.'));
    }

    public function restoreConfigBackup(string $backupPath): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Restore config backup: :path', ['path' => basename($path)]),
        );

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'restore',
            $path,
            backupPath: $backupPath,
            engine: $this->resolvedConfigEngineForPath($path),
        );

        $this->toastSuccess(__('Restore queued — progress shows in the banner above.'));
    }

    public function render(): View
    {
        // No $this->server->refresh(): route binding (first load) and Livewire's
        // Eloquent synthesizer (subsequent requests) already provide a current row.
        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        $catalog = app(ServerConfigFileCatalog::class);

        $autocomplete = [];
        if ($this->config_selected_path !== null) {
            $autocomplete = $catalog->autocompleteForPath((string) $this->config_selected_path);
        }

        $configConsoleRun = $this->configConsoleRunForBanner();

        return view('livewire.servers.workspace-configuration', array_merge(
            $this->configRevisionViewData(),
            [
                'server' => $this->server,
                'configAutocomplete' => $autocomplete,
                'configFileType' => $this->config_selected_path !== null
                    ? $catalog->fileTypeForPath((string) $this->config_selected_path)
                    : 'conf',
                'opsReady' => $this->serverOpsReady(),
                'isDeployer' => $this->currentUserIsDeployer(),
                'configConsoleRun' => $configConsoleRun,
                'configReturnContext' => $this->configReturnContext(),
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
            ],
        ));
    }

    /**
     * Contextual back navigation when the operator arrived from the webserver
     * workspace Config tab (scope filter + return_sub preserve where they came from).
     *
     * @return array{engine: string, engine_label: string, back_label: string, back_url: string, title: string, description: string}|null
     */
    public function configReturnContext(): ?array
    {
        $from = $this->resolvedConfigFrom();
        if (! in_array($from, ['webserver', 'edge-proxy'], true) || $this->config_scope === '') {
            return null;
        }

        $engine = strtolower(trim($this->config_scope));
        if (! in_array($engine, self::webserverConfigurationScopes(), true)) {
            return null;
        }

        $catalog = array_merge(
            WebserverWorkspaceViewData::webserverCatalog(),
            EdgeProxyWorkspaceViewData::edgeProxyCatalog(),
        );
        $engineLabel = (string) ($catalog[$engine]['label'] ?? ucfirst($engine));
        $returnSub = $this->sanitizedConfigReturnSub($engine);

        if ($from === 'edge-proxy') {
            return [
                'engine' => $engine,
                'engine_label' => $engineLabel,
                'back_label' => __('Back to :engine edge proxy', ['engine' => $engineLabel]),
                'back_url' => route('servers.edge-proxy', [
                    'server' => $this->server,
                    'tab' => $engine,
                    'sub' => $returnSub,
                ]),
                'title' => __('Opened from :engine edge proxy', ['engine' => $engineLabel]),
                'description' => __('The edge proxy Config tab sends you here to edit allowlisted :engine files with validate, diff, backup, and restore. The file list is filtered to this stack — use “Show all files” to browse everything on the server. When you are done, use the back link to return to overview, live state, logs, and service controls.', [
                    'engine' => $engineLabel,
                ]),
            ];
        }

        return [
            'engine' => $engine,
            'engine_label' => $engineLabel,
            'back_label' => __('Back to :engine webserver', ['engine' => $engineLabel]),
            'back_url' => route('servers.webserver', [
                'server' => $this->server,
                'tab' => $engine,
                'sub' => $returnSub,
            ]),
            'title' => __('Opened from :engine webserver', ['engine' => $engineLabel]),
            'description' => __('The webserver Config tab sends you here to edit allowlisted :engine files with validate, diff, backup, and restore. The file list is filtered to this stack — use “Show all files” to browse everything on the server. When you are done, use the back link to return to overview, live state, logs, and service controls.', [
                'engine' => $engineLabel,
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    public static function webserverConfigurationScopes(): array
    {
        return ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'envoy', 'openresty'];
    }

    /**
     * Edge proxy engines (Traefik, HAProxy, …) must return to the edge-proxy
     * workspace even when older links used from=webserver.
     */
    private function resolvedConfigFrom(): string
    {
        $from = strtolower(trim($this->config_from));
        $engine = strtolower(trim($this->config_scope));

        if ($from === 'webserver' && isset(EdgeProxyWorkspaceViewData::edgeProxyCatalog()[$engine])) {
            return 'edge-proxy';
        }

        return $from;
    }

    private function sanitizedConfigReturnSub(string $engine): string
    {
        $sub = strtolower(trim($this->config_return_sub));
        if ($sub === '' || $sub === 'config') {
            return 'overview';
        }

        $allowedByEngine = [
            'nginx' => ['overview', 'logs', 'info', 'hosts', 'upstreams', 'certs', 'modules', 'cache', 'workers'],
            'caddy' => ['overview', 'logs', 'info', 'routes', 'upstreams', 'certs', 'snippets', 'admin'],
            'apache' => ['overview', 'logs', 'info', 'vhosts', 'modules', 'cache', 'certs', 'workers'],
            'openlitespeed' => ['overview', 'logs', 'info', 'vhosts', 'listeners', 'extapps', 'modules', 'cache'],
            'traefik' => [
                'overview', 'logs', 'info',
                'routers', 'services', 'middlewares', 'entrypoints',
                'tcprouters', 'tcpservices', 'udprouters', 'udpservices', 'tls', 'providers',
                'static', 'dynamic',
            ],
            'haproxy' => ['overview', 'logs', 'info', 'frontends', 'backends', 'ssl', 'runtime'],
            'envoy' => ['overview', 'logs', 'info', 'listeners', 'virtualhosts', 'clusters', 'stats', 'runtime', 'static'],
        ];

        $allowed = $allowedByEngine[$engine] ?? ['overview'];

        return in_array($sub, $allowed, true) ? $sub : 'overview';
    }

    protected function stashConfigDraft(string $path, string $contents): void
    {
        $this->config_drafts[$path] = $contents;
    }

    protected function reloadConfigCatalog(): void
    {
        if ($this->configCatalogLoading) {
            return;
        }

        $this->configCatalogLoaded = false;
        $this->loadConfigCatalog();
    }

    /**
     * After a full-page load with ?file=…, re-fetch file contents once the
     * catalog has finished discovering allowlisted paths.
     */
    protected function maybeRestoreConfigFileFromUrl(): void
    {
        if ($this->configRestoredFromUrl || $this->config_selected_path === null || $this->config_selected_path === '') {
            return;
        }

        if ($this->pending_load_console_id !== null) {
            return;
        }

        if ($this->config_file_loaded) {
            $this->configRestoredFromUrl = true;

            return;
        }

        $path = $this->config_selected_path;

        try {
            $this->assertCatalogPath($path);
        } catch (\InvalidArgumentException) {
            $this->config_selected_path = null;

            return;
        }

        $this->configRestoredFromUrl = true;
        $this->loadConfigFile($path);
    }

    protected function configCatalogCacheKey(?string $scope, ?string $search): string
    {
        return 'dply.server-config-catalog:'.$this->server->id.':'.md5(($scope ?? '').'|'.($search ?? ''));
    }

    protected function configConsoleRunForBanner(): ?ConsoleAction
    {
        $run = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        if ($run === null || $this->isConfigReadConsoleRun($run)) {
            return null;
        }

        return $run;
    }

    protected function isConfigReadConsoleRun(ConsoleAction $run): bool
    {
        return str_starts_with((string) $run->label, 'Load config:');
    }

    protected function pickupQueuedConfigLoad(): void
    {
        if ($this->pending_load_console_id === null) {
            return;
        }

        $row = ConsoleAction::query()->find($this->pending_load_console_id);
        if ($row === null) {
            $this->pending_load_console_id = null;
            $this->pending_load_path = null;

            return;
        }

        if (! in_array($row->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return;
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED && $this->pending_load_path !== null) {
            $cached = Cache::pull(
                RunServerConfigOpJob::readResultCacheKey($this->pending_load_console_id),
            );
            if (is_array($cached)) {
                $path = $this->pending_load_path;
                $this->applyLoadedConfigFile(
                    $path,
                    (string) ($cached['contents'] ?? ''),
                    truncated: (bool) ($cached['truncated'] ?? false),
                    fromCache: false,
                );
                Cache::forget($this->configCatalogCacheKey(
                    $this->config_scope !== '' ? $this->config_scope : null,
                    $this->config_search !== '' ? $this->config_search : null,
                ));
                $this->refreshCachedFlagsOnCatalog();
            }

            $row->forceFill(['dismissed_at' => now()])->save();
        } elseif ($row->status === ConsoleAction::STATUS_FAILED) {
            $this->toastError(__('Failed to load config file.'));
            $this->config_selected_path = null;
            $this->config_file_loaded = false;
            $this->config_loaded_from_cache = false;
            $this->config_content_cached_at = null;
            $this->config_contents = '';
            $this->config_original_contents = '';
            $row->forceFill(['dismissed_at' => now()])->save();
        }

        $this->pending_load_console_id = null;
        $this->pending_load_path = null;
    }

    protected function refreshRemoteConfigBackups(): void
    {
        if ($this->config_selected_path === null) {
            $this->config_backups = [];

            return;
        }

        $path = (string) $this->config_selected_path;
        $engine = $this->resolvedConfigEngineForPath($path);

        try {
            $this->config_backups = $engine !== null
                ? app(RemoteWebserverConfigService::class)->listBackups($this->server, $engine, $path)
                : app(RemoteServerConfigService::class)->listBackups($this->server, $path);
        } catch (\Throwable) {
            $this->config_backups = [];
        }
    }

    protected function guardConfigAction(bool $allowReadOnly = false): bool
    {
        if (! $allowReadOnly && $this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot edit server config.'));

            return false;
        }
        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before this action.'));

            return false;
        }

        return true;
    }

    protected function assertCatalogPath(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        foreach (config('server_manage.allowed_config_paths_exact', []) as $exact) {
            if ($normalized === $exact) {
                return;
            }
        }

        foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
    }

    protected function resolvedConfigEngineForPath(string $path): ?string
    {
        return app(ServerConfigFileCatalog::class)->webserverEngineForPath($path);
    }

    protected function seedConfigurationConsoleAction(string $label): string
    {
        ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [
                ConsoleAction::STATUS_COMPLETED,
                ConsoleAction::STATUS_FAILED,
            ])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $this->server->getMorphClass(),
            'subject_id' => $this->server->id,
            'kind' => 'manage_action',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label.' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }

    protected function tryHydrateSelectedFileFromCacheOnMount(): void
    {
        if ($this->config_selected_path === null || $this->config_selected_path === '' || ! $this->serverOpsReady()) {
            return;
        }

        try {
            $this->assertCatalogPath((string) $this->config_selected_path);
        } catch (\InvalidArgumentException) {
            $this->config_selected_path = null;

            return;
        }

        $this->hydrateConfigFileFromCache((string) $this->config_selected_path);
    }

    protected function hydrateConfigFileFromCache(string $path): bool
    {
        $cached = Cache::get(RunServerConfigOpJob::fileContentCacheKey((string) $this->server->id, $path));
        // Treat an empty cached payload as a miss — a stale empty entry (e.g. from
        // a failed read during the old queued flow) must not show a blank editor;
        // fall through to a fresh direct read instead.
        if (! is_array($cached) || ! array_key_exists('contents', $cached) || (string) $cached['contents'] === '') {
            return false;
        }

        $this->applyLoadedConfigFile(
            $path,
            (string) $cached['contents'],
            truncated: (bool) ($cached['truncated'] ?? false),
            fromCache: true,
            cachedAt: is_string($cached['cached_at'] ?? null) ? $cached['cached_at'] : null,
        );

        return true;
    }

    protected function applyLoadedConfigFile(
        string $path,
        string $contents,
        bool $truncated,
        bool $fromCache,
        ?string $cachedAt = null,
    ): void {
        $this->config_selected_path = $path;
        $this->config_contents = $contents;
        $this->config_original_contents = $contents;
        $this->config_truncated_on_load = $truncated;
        $this->config_file_loaded = true;
        $this->config_loaded_from_cache = $fromCache;
        $this->config_content_cached_at = $fromCache ? $cachedAt : null;
        $this->pending_load_path = null;
        $this->pending_load_console_id = null;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->closeConfigSaveDiff();
        $this->closeConfigRevisionDiff();
        $this->stashConfigDraft($path, $contents);
        $this->refreshCachedFlagsOnCatalog();
        $this->refreshRemoteConfigBackups();
        $this->refreshConfigRevisionState();
    }

    protected function enterPendingConfigFileLoad(string $path): void
    {
        $this->pending_load_path = $path;
        $this->pending_load_console_id = null;
        $this->config_file_loaded = false;
        $this->config_loaded_from_cache = false;
        $this->config_content_cached_at = null;
        $this->config_contents = '';
        $this->config_truncated_on_load = false;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->config_backups = [];
        $this->config_original_contents = '';
        $this->closeConfigSaveDiff();
        $this->closeConfigRevisionDiff();
    }

    protected function refreshCachedFlagsOnCatalog(): void
    {
        if ($this->groupedConfigFiles === []) {
            return;
        }

        $serverId = (string) $this->server->id;

        foreach ($this->groupedConfigFiles as $groupKey => $group) {
            foreach ($group['files'] as $index => $file) {
                $path = (string) ($file['path'] ?? '');
                $this->groupedConfigFiles[$groupKey]['files'][$index]['cached'] = $path !== ''
                    && Cache::has(RunServerConfigOpJob::fileContentCacheKey($serverId, $path));
            }
        }
    }

    protected function markPathCachedInCatalog(string $path, bool $cached): void
    {
        foreach ($this->groupedConfigFiles as $groupKey => $group) {
            foreach ($group['files'] as $index => $file) {
                if (($file['path'] ?? '') === $path) {
                    $this->groupedConfigFiles[$groupKey]['files'][$index]['cached'] = $cached;
                }
            }
        }
    }
}
