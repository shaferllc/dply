<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\UninstallCacheServiceJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceAuth;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceCommandPolicy;
use App\Support\Servers\CacheServiceConfigWriter;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceKeyspaceSampler;
use App\Support\Servers\CacheServiceMemoryConfig;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceCaches extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Active workspace tab. URL-bound so deep links + back/forward work. */
    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $workspace_tab = 'overview';

    /**
     * Active sub-tab inside a per-engine tab. Per-engine layouts stack a lot of
     * cards (status, console, stats, configure) so we group them under sub-tabs.
     * URL-bound so deep links open to the right sub-section. Default 'overview'
     * is the only sub-tab that always exists; redis-family engines also expose
     * 'console' and 'stats'; both engine families expose 'configure'.
     */
    #[Url(as: 'subtab', except: 'overview', history: true)]
    public string $engine_subtab = 'overview';

    /** @var list<string> */
    public const ENGINE_SUBTABS = ['overview', 'console', 'stats', 'configure'];

    /**
     * Active instance name within the current per-engine tab. URL-bound so
     * deep links work. Defaults to `default` so existing single-instance
     * servers (and any first-time install) continue to operate on the
     * legacy single-instance row without code changes elsewhere.
     */
    #[Url(as: 'instance', except: ServerCacheService::DEFAULT_INSTANCE_NAME, history: true)]
    public string $active_instance = ServerCacheService::DEFAULT_INSTANCE_NAME;

    /** Show/hide the "Add another instance" inline form. */
    public bool $showAddInstanceForm = false;

    public string $newInstanceName = '';

    public ?int $newInstancePort = null;

    /**
     * Lazy-loaded snapshot of the engine's main config file. Set by `loadCacheConfig()`,
     * cleared by `hideCacheConfig()` or by switching tabs. Scoped to the engine of the
     * currently-active tab, so no per-engine indexing is needed on the property itself.
     */
    public ?string $cacheConfigContent = null;

    public ?string $cacheConfigPath = null;

    public ?string $cacheConfigError = null;

    public bool $cacheConfigEditing = false;

    public string $cacheConfigDraft = '';

    /** Form input for setting the AUTH password on the redis-family engine of the current tab. */
    public string $new_auth_password = '';

    /**
     * Lazy-loaded snapshot of `CLIENT LIST` for redis-family engines.
     *
     * @var list<array{id: string, addr: string, name: string, age: string, idle: string, db: string}>|null
     */
    public ?array $cacheClients = null;

    public ?string $cacheClientsError = null;

    /** True after `loadCacheMemorySettings` populates the form below. */
    public bool $cacheMemoryLoaded = false;

    public string $cache_maxmemory = '';

    public string $cache_maxmemory_policy = 'noeviction';

    public ?string $cacheMemoryError = null;

    /** Current REPL input value (cleared after each successful run). */
    public string $replInput = '';

    /**
     * Bounded ring buffer of past REPL entries. Capped at REPL_HISTORY_LIMIT;
     * the front is dropped on overflow. Each entry is shaped like:
     *   ['ts' => string, 'cmd' => string, 'output' => string,
     *    'exit_code' => int, 'kind' => 'sent'|'error']
     *
     * @var list<array{ts: string, cmd: string, output: string, exit_code: int, kind: string}>
     */
    public array $replHistory = [];

    /** Mutating commands require this toggle. NOT persisted across mounts. */
    public bool $replUnlocked = false;

    public const REPL_HISTORY_LIMIT = 50;

    /**
     * Bounded ring buffer of dashboard samples. Capped at KEYSPACE_SAMPLE_LIMIT.
     *
     * @var list<array<string, mixed>>
     */
    public array $keyspaceSamples = [];

    public bool $keyspaceLoaded = false;

    public ?string $keyspaceError = null;

    public const KEYSPACE_SAMPLE_LIMIT = 60;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        $next = in_array($tab, $allowed, true) ? $tab : 'overview';

        // Reset all per-engine UI buffers when switching tabs — the config viewer / memory form /
        // clients list are scoped to whichever engine the operator is looking at, and silently
        // carrying redis config to the keydb tab would be confusing at best.
        if ($next !== $this->workspace_tab) {
            $this->resetPerEngineUiState();
            // Switching engines means the previously-selected instance name
            // probably doesn't exist on the new engine. Reset to `default` so
            // the per-engine actions land on the legacy single-instance row.
            $this->active_instance = ServerCacheService::DEFAULT_INSTANCE_NAME;
            $this->showAddInstanceForm = false;
        }

        $this->workspace_tab = $next;
    }

    /**
     * Switch the active instance within the current engine tab. Resets the
     * lazy-loaded per-instance UI buffers (config viewer / clients list / REPL
     * history / keyspace samples) so they don't carry state from the previous
     * instance into the next one.
     *
     * Validates against the actual instances on the server — passing a name
     * that doesn't exist on this server falls back to `default` to keep the
     * URL well-formed.
     */
    public function setActiveInstance(string $name): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $exists = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', $name)
            ->exists();

        $this->active_instance = $exists ? $name : ServerCacheService::DEFAULT_INSTANCE_NAME;

        $this->resetPerEngineUiState();
    }

    public function openAddInstanceForm(): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null || ! ServerCacheService::engineSupportsAuth($engine)) {
            return;
        }

        // Suggest the next free port: walk up from the engine's default until
        // we find one not in use on this server. This lets the operator just
        // hit "Add" without thinking about port assignment.
        $usedPorts = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->pluck('port')
            ->all();
        $candidate = ServerCacheService::defaultPortFor($engine);
        while (in_array($candidate, $usedPorts, true)) {
            $candidate++;
        }

        $this->newInstanceName = '';
        $this->newInstancePort = $candidate;
        $this->showAddInstanceForm = true;
    }

    public function closeAddInstanceForm(): void
    {
        $this->showAddInstanceForm = false;
        $this->newInstanceName = '';
        $this->newInstancePort = null;
    }

    public function submitAddInstanceForm(): void
    {
        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $this->addInstance($engine, trim($this->newInstanceName), (int) ($this->newInstancePort ?? 0));

        // Only collapse the form if the call actually succeeded — toasts on
        // failure leave the form open so the operator can fix and retry.
        $exists = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', trim($this->newInstanceName))
            ->exists();

        if ($exists) {
            $this->active_instance = trim($this->newInstanceName);
            $this->closeAddInstanceForm();
        }
    }

    public function setEngineSubtab(string $subtab): void
    {
        $next = in_array($subtab, self::ENGINE_SUBTABS, true) ? $subtab : 'overview';

        if ($next !== $this->engine_subtab) {
            // Lazy-loaded buffers are scoped to a sub-tab. Switching from
            // Configure to Stats shouldn't carry the AUTH password input
            // forward, and switching off Console should keep the unlock-toggle
            // session-scoped trust intact (only mount resets it). Wipe the
            // narrowest set of buffers here.
            $this->cacheConfigContent = null;
            $this->cacheConfigPath = null;
            $this->cacheConfigError = null;
            $this->cacheConfigEditing = false;
            $this->cacheConfigDraft = '';
            $this->cacheClients = null;
            $this->cacheClientsError = null;
            $this->cacheMemoryLoaded = false;
            $this->cache_maxmemory = '';
            $this->cache_maxmemory_policy = 'noeviction';
            $this->cacheMemoryError = null;
            $this->new_auth_password = '';
            $this->keyspaceSamples = [];
            $this->keyspaceLoaded = false;
            $this->keyspaceError = null;
        }

        $this->engine_subtab = $next;
    }

    /**
     * Any cache service on this server in a queued/in-flight state. Mutating actions reject while
     * busy because apt/dpkg on the box only allow one operation at a time — even per-engine ones
     * like "restart valkey" would block on the redis apt purge holding the dpkg lock.
     */
    protected function cacheServiceBusy(): bool
    {
        return $this->cacheServices()->contains(fn (ServerCacheService $row) => in_array($row->status, [
            ServerCacheService::STATUS_PENDING,
            ServerCacheService::STATUS_INSTALLING,
            ServerCacheService::STATUS_UNINSTALLING,
        ], true));
    }

    protected function rejectIfCacheBusy(): bool
    {
        if ($this->cacheServiceBusy()) {
            $this->toastError(__('Cache service is currently changing — wait for the running operation to finish before doing anything else.'));

            return true;
        }

        return false;
    }

    public function refreshCacheCapabilities(ServerCacheServiceHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);
        $this->toastSuccess(__('Rechecked the server for cache services.'));
    }

    /**
     * Queue an install for the requested engine. Multi-engine is now allowed: Redis + Memcached
     * side-by-side is a legit pattern (Redis for queues/Horizon, Memcached for app cache).
     */
    public function installCacheService(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $existing = $this->cacheServiceFor($engine);

        $row = $existing ?? ServerCacheService::query()->create([
            'server_id' => $this->server->id,
            'engine' => $engine,
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => ServerCacheService::defaultPortFor($engine),
        ]);

        // Re-run install on an existing row only when it's in failed/stopped — otherwise the row
        // is already installing or running and we'd just queue redundant work.
        if (! in_array($row->status, [
            ServerCacheService::STATUS_PENDING,
            ServerCacheService::STATUS_FAILED,
            ServerCacheService::STATUS_STOPPED,
        ], true)) {
            $this->toastError(__(':engine is already installing or running.', ['engine' => $engine]));

            return;
        }

        // Clear stale cancel flag from a prior failed run so the worker doesn't immediately abort.
        if ($row->cancel_requested_at !== null) {
            $row->update(['cancel_requested_at' => null]);
        }

        InstallCacheServiceJob::dispatch($row->id);
        $this->forgetStats($row);
        $this->toastSuccess(__('Installing :engine — refresh in a moment to see status.', ['engine' => $engine]));
        $this->workspace_tab = $engine;
    }

    /**
     * Queue an install for an additional instance of the given engine. The
     * default instance (legacy single-instance) is created via
     * `installCacheService`; this method is for second/third/Nth instances on
     * different ports. Memcached is rejected because its multi-instance
     * setup is out of scope for v1.
     *
     * @param  string  $engine  Engine slug
     * @param  string  $name  Operator-supplied instance name
     *                        ([a-z0-9][a-z0-9-]{0,31}); 'default' is reserved
     * @param  int  $port  TCP port for the new instance
     */
    public function addInstance(string $engine, string $name, int $port): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        if (! ServerCacheService::engineSupportsAuth($engine)) {
            $this->toastError(__('Memcached multi-instance is not supported. Use the default instance only.'));

            return;
        }

        if ($name === ServerCacheService::DEFAULT_INSTANCE_NAME) {
            $this->toastError(__("'default' is reserved — pick a different name (e.g. sessions, queue, cache)."));

            return;
        }

        if (! ServerCacheService::isValidInstanceName($name)) {
            $this->toastError(__('Instance name must be lowercase letters/digits/hyphens, starting with a letter or digit, max 32 chars.'));

            return;
        }

        if ($port < 1 || $port > 65535) {
            $this->toastError(__('Port must be between 1 and 65535.'));

            return;
        }

        // Reject reserved port ranges that the OS or other dply features need —
        // operators almost never want to bind a cache to e.g. 22 (SSH), 80
        // (HTTP), or the well-known DB ports.
        if (in_array($port, [22, 25, 80, 443, 3306, 5432, 6443, 8080, 11211], true)) {
            $this->toastError(__('Port :port is reserved for another service. Pick a different port (e.g. :suggested).', [
                'port' => $port,
                'suggested' => $port === 11211 ? 11212 : ServerCacheService::defaultPortFor($engine) + 1,
            ]));

            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        // Per-server uniqueness on (server_id, port) and (server_id, engine, name)
        // is enforced at the DB layer, but a friendly toast beats a 500 page
        // when the operator picks a name or port that already exists.
        $portTaken = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('port', $port)
            ->exists();
        if ($portTaken) {
            $this->toastError(__('Port :port is already used by another cache service on this server.', ['port' => $port]));

            return;
        }

        $nameTaken = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', $name)
            ->exists();
        if ($nameTaken) {
            $this->toastError(__("An :engine instance named ':name' already exists on this server.", ['engine' => $engine, 'name' => $name]));

            return;
        }

        $row = ServerCacheService::query()->create([
            'server_id' => $this->server->id,
            'engine' => $engine,
            'name' => $name,
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => $port,
        ]);

        InstallCacheServiceJob::dispatch($row->id);
        $this->toastSuccess(__('Installing :engine instance ":name" on :port — refresh in a moment to see status.', [
            'engine' => $engine,
            'name' => $name,
            'port' => $port,
        ]));
        $this->workspace_tab = $engine;
    }

    /**
     * Cancel an in-flight install for a specific engine. Same three branches as before:
     * PENDING → delete the row; INSTALLING → flip cancel_requested_at; UNINSTALLING → can't cancel.
     */
    public function cancelCacheServiceChange(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            return;
        }

        if ($row->status === ServerCacheService::STATUS_PENDING) {
            $row->delete();
            $this->toastSuccess(__('Cancelled — the queued :engine change was discarded before apt started.', ['engine' => $engine]));

            return;
        }

        if ($row->status === ServerCacheService::STATUS_INSTALLING) {
            if ($row->cancel_requested_at !== null) {
                $this->toastSuccess(__('Cancellation already requested — finishing the current step, then reverting.'));

                return;
            }

            $row->update(['cancel_requested_at' => now()]);
            $this->toastSuccess(__('Cancelling :engine — the job will stop at the next output chunk and apt-purge to revert.', ['engine' => $engine]));

            return;
        }

        if ($row->status === ServerCacheService::STATUS_UNINSTALLING) {
            $this->toastError(__('Uninstall is already running — wait for it to finish.'));

            return;
        }

        $this->toastError(__('Nothing to cancel: the row is :status.', ['status' => $row->status]));
    }

    public function uninstallCacheService(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine to uninstall.', ['engine' => $engine]));

            return;
        }

        UninstallCacheServiceJob::dispatch($row->id);
        $this->forgetStats($row);
        $this->toastSuccess(__('Uninstall queued for :engine.', ['engine' => $engine]));
    }

    public function restartCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($engine, $executor, $audit, 'restart', null, ServerCacheServiceAuditEvent::EVENT_RESTARTED);
    }

    public function stopCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($engine, $executor, $audit, 'stop', ServerCacheService::STATUS_STOPPED, ServerCacheServiceAuditEvent::EVENT_STOPPED);
    }

    public function startCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($engine, $executor, $audit, 'start', ServerCacheService::STATUS_RUNNING, ServerCacheServiceAuditEvent::EVENT_STARTED);
    }

    /**
     * Run the engine's version probe and persist the result. Used to backfill the Version field
     * when the original install probe came back empty (e.g. binary not yet on PATH).
     */
    public function probeCacheServiceVersion(string $engine, ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine to probe.', ['engine' => $engine]));

            return;
        }

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:version-probe:'.$row->engine,
                CacheServiceInstallScripts::versionProbeScript($row->engine),
                timeoutSeconds: 30,
                asRoot: true,
            );

            $version = trim($output->buffer);
            if ($version === '') {
                $this->toastError(__('Could not detect a version for :engine.', ['engine' => $row->engine, 'name' => $row->name]));

                return;
            }

            $row->update(['version' => Str::limit($version, 64, '')]);
            $this->forgetStats($row);
            $this->toastSuccess(__('Detected :engine :version.', ['engine' => $row->engine, 'name' => $row->name, 'version' => $version]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * SSH-cat the engine's main config file and stash the contents on the component for the
     * read-only viewer card. Scoped to the engine of the currently-open tab (= $this->workspace_tab).
     */
    public function loadCacheConfig(ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->cacheConfigError = __('Switch to an engine tab to view its config.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->cacheConfigError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        try {
            $path = CacheServiceInstallScripts::configFilePathFor($row->engine);
        } catch (\InvalidArgumentException $e) {
            $this->cacheConfigError = $e->getMessage();

            return;
        }

        $this->cacheConfigPath = $path;
        $this->cacheConfigError = null;

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:config:'.$row->engine,
                'if [ -r '.escapeshellarg($path).' ]; then head -c 65536 '.escapeshellarg($path).'; else echo "[dply] config file not readable: '.$path.'" >&2; exit 2; fi',
                timeoutSeconds: 30,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(trim($output->buffer) ?: 'cat failed.');
            }

            $this->cacheConfigContent = $output->buffer;
        } catch (\Throwable $e) {
            $this->cacheConfigContent = null;
            $this->cacheConfigError = $e->getMessage();
        }
    }

    public function hideCacheConfig(): void
    {
        $this->cacheConfigContent = null;
        $this->cacheConfigPath = null;
        $this->cacheConfigError = null;
        $this->cacheConfigEditing = false;
        $this->cacheConfigDraft = '';
    }

    public function startEditingCacheConfig(ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        if ($this->cacheConfigContent === null) {
            $this->loadCacheConfig($executor);
            if ($this->cacheConfigContent === null) {
                return;
            }
        }

        $this->cacheConfigDraft = $this->cacheConfigContent;
        $this->cacheConfigEditing = true;
    }

    public function cancelEditingCacheConfig(): void
    {
        $this->cacheConfigEditing = false;
        $this->cacheConfigDraft = '';
    }

    public function saveCacheConfig(
        CacheServiceConfigWriter $writer,
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to edit its config.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        $this->validate([
            'cacheConfigDraft' => ['required', 'string', 'max:262144'],
        ], [
            'cacheConfigDraft.max' => __('Config exceeds 256 KB. Trim it before saving.'),
        ], [
            'cacheConfigDraft' => __('config'),
        ]);

        try {
            $writer->write($row->server, $row, $this->cacheConfigDraft);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->cacheConfigContent = $this->cacheConfigDraft;
        $this->cacheConfigEditing = false;
        $this->cacheConfigDraft = '';

        $audit->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
            ['engine' => $row->engine, 'name' => $row->name, 'bytes' => strlen((string) $this->cacheConfigContent)],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Config saved and :engine restarted.', ['engine' => $row->engine, 'name' => $row->name]));
    }

    public function loadCacheClients(CacheServiceStats $stats): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->cacheClientsError = __('Switch to an engine tab to view its clients.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->cacheClientsError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->cacheClientsError = __(':engine has no CLIENT LIST equivalent.', ['engine' => $row->engine, 'name' => $row->name]);

            return;
        }

        $this->cacheClientsError = null;
        $this->cacheClients = $stats->clients($row->server, $row);
    }

    public function hideCacheClients(): void
    {
        $this->cacheClients = null;
        $this->cacheClientsError = null;
    }

    public function loadCacheMemorySettings(CacheServiceMemoryConfig $memory): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->cacheMemoryError = __('Switch to an engine tab to view its memory settings.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->cacheMemoryError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->cacheMemoryError = __(':engine has no maxmemory directive — memory limits are tuned via systemd or the engine launch flags.', ['engine' => $row->engine, 'name' => $row->name]);

            return;
        }

        try {
            $current = $memory->read($row->server, $row);
        } catch (\Throwable $e) {
            $this->cacheMemoryError = $e->getMessage();

            return;
        }

        $this->cache_maxmemory = (string) ($current['maxmemory'] ?? '');
        $this->cache_maxmemory_policy = (string) ($current['maxmemory_policy'] ?? 'noeviction');
        $this->cacheMemoryLoaded = true;
        $this->cacheMemoryError = null;
    }

    public function hideCacheMemorySettings(): void
    {
        $this->cacheMemoryLoaded = false;
        $this->cache_maxmemory = '';
        $this->cache_maxmemory_policy = 'noeviction';
        $this->cacheMemoryError = null;
    }

    public function saveCacheMemorySettings(
        CacheServiceMemoryConfig $memory,
        CacheServiceAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to update its memory settings.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine does not support maxmemory.', ['engine' => $row->engine, 'name' => $row->name]));

            return;
        }

        $this->validate([
            'cache_maxmemory' => ['nullable', 'string', 'regex:/^(0|\d+(b|kb|mb|gb))$/i'],
            'cache_maxmemory_policy' => ['nullable', 'string', 'in:'.implode(',', CacheServiceMemoryConfig::POLICIES)],
        ], [
            'cache_maxmemory.regex' => __('maxmemory must be 0 or a value like 256mb / 1gb.'),
        ]);

        $maxmemory = trim($this->cache_maxmemory);
        $policy = trim($this->cache_maxmemory_policy);

        try {
            $memory->write(
                $row->server,
                $row,
                $maxmemory === '' ? null : strtolower($maxmemory),
                $policy === '' ? null : strtolower($policy),
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $audit->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_MEMORY_UPDATED,
            [
                'engine' => $row->engine, 'name' => $row->name,
                'maxmemory' => $maxmemory ?: null,
                'maxmemory_policy' => $policy ?: null,
            ],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Memory settings applied to :engine.', ['engine' => $row->engine, 'name' => $row->name]));
    }

    public function generateAuthPassword(): void
    {
        $this->new_auth_password = Str::password(32, symbols: false);
    }

    public function setAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to set its AUTH password.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine, 'name' => $row->name]));

            return;
        }

        $this->validate([
            'new_auth_password' => ['required', 'string', 'min:12', 'max:256', 'regex:/^[\x21-\x7E]+$/'],
        ], [], [
            'new_auth_password' => __('AUTH password'),
        ]);

        try {
            $auth->setRequirePass($row->server, $row, $this->new_auth_password);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $row->update(['auth_password' => $this->new_auth_password]);
        $this->new_auth_password = '';

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
            ['engine' => $row->engine, 'name' => $row->name],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('AUTH password set on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
    }

    public function clearAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to clear its AUTH password.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine, 'name' => $row->name]));

            return;
        }

        try {
            $auth->clearRequirePass($row->server, $row);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $row->update(['auth_password' => null]);

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_AUTH_CLEARED,
            ['engine' => $row->engine, 'name' => $row->name],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Cleared AUTH password on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
    }

    /**
     * Run a single redis-cli command from the workspace REPL. Read-only commands run
     * unconditionally. Mutating commands require the unlock toggle. A small set of
     * disruptive verbs (SHUTDOWN, MIGRATE, REPLICAOF, etc.) are blocked outright —
     * those go through the dedicated buttons or stay out of the workspace.
     *
     * Every attempt — successful, denied, or blocked — writes an audit row. The
     * audit meta records only the first command token (the verb), never arguments,
     * to keep key contents and AUTH passwords out of the audit log.
     */
    public function runReplCommand(
        CacheServiceCli $cli,
        CacheServiceCommandPolicy $policy,
        CacheServiceAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to run commands.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Memcached has no redis-cli surface — use the connection snippet to talk to it from your app.'));

            return;
        }

        $command = trim($this->replInput);
        if ($command === '') {
            return;
        }

        $verb = strtoupper(preg_split('/\s+/', $command)[0] ?? '');

        // Hard-block check first — even with unlock on, these don't run.
        if ($policy->isBlocked($command)) {
            $this->pushReplEntry(
                command: $command,
                output: __('Blocked: :verb is not allowed from the REPL. Use the engine controls instead.', ['verb' => $verb]),
                exitCode: -1,
                kind: 'error',
            );
            $this->replInput = '';
            $audit->record(
                $row->server,
                ServerCacheServiceAuditEvent::EVENT_REPL_BLOCKED,
                ['engine' => $row->engine, 'name' => $row->name, 'verb' => $verb],
                auth()->user(),
            );

            return;
        }

        $isReadOnly = $policy->isReadOnly($command);

        if (! $isReadOnly && ! $this->replUnlocked) {
            $this->pushReplEntry(
                command: $command,
                output: __('Read-only — flip the unlock toggle to run mutating commands.'),
                exitCode: -1,
                kind: 'error',
            );
            $this->replInput = '';
            $audit->record(
                $row->server,
                ServerCacheServiceAuditEvent::EVENT_REPL_DENIED,
                ['engine' => $row->engine, 'name' => $row->name, 'verb' => $verb],
                auth()->user(),
            );

            return;
        }

        try {
            $output = $cli->execute($row->server, $row, $command);
            $this->pushReplEntry(
                command: $command,
                output: rtrim($output->buffer, "\n"),
                exitCode: $output->exitCode,
                kind: 'sent',
            );
        } catch (\Throwable $e) {
            $this->pushReplEntry(
                command: $command,
                output: $e->getMessage(),
                exitCode: -1,
                kind: 'error',
            );
            $this->replInput = '';

            return;
        }

        $this->replInput = '';

        $audit->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
            [
                'engine' => $row->engine, 'name' => $row->name,
                'verb' => $verb,
                'mutating' => ! $isReadOnly,
                'exit_code' => $output->exitCode ?? 0,
            ],
            auth()->user(),
        );

        // A mutating command can change INFO numbers; bust the cached snapshot so the
        // overview reflects it on next render.
        if (! $isReadOnly) {
            $this->forgetStats($row);
        }
    }

    public function toggleReplUnlock(CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;

        $this->replUnlocked = ! $this->replUnlocked;

        if ($row) {
            $audit->record(
                $row->server,
                $this->replUnlocked
                    ? ServerCacheServiceAuditEvent::EVENT_REPL_UNLOCKED
                    : ServerCacheServiceAuditEvent::EVENT_REPL_LOCKED,
                ['engine' => $row->engine, 'name' => $row->name],
                auth()->user(),
            );
        }

        $this->toastSuccess($this->replUnlocked
            ? __('Unlocked — mutating commands will now run. Every command is recorded in the audit log.')
            : __('Locked — only read-only commands will run.'));
    }

    public function clearReplHistory(): void
    {
        $this->replHistory = [];
        $this->replInput = '';
    }

    /**
     * Pull a fresh INFO sample, append to the dashboard ring buffer, and trim. The
     * sampler computes deltas relative to the previous sample for ops/sec and hit-rate
     * windows; absolute values for memory and clients come straight from the latest INFO.
     */
    public function loadKeyspaceDashboard(CacheServiceStats $stats, CacheServiceKeyspaceSampler $sampler): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->keyspaceError = __('Switch to an engine tab to view its keyspace dashboard.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->keyspaceError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->keyspaceError = __(':engine has no INFO surface — see the connection snippet for memcached stats.', ['engine' => $row->engine, 'name' => $row->name]);

            return;
        }

        $raw = $stats->rawInfo($row->server, $row);
        if ($raw === null) {
            $this->keyspaceError = __('Could not read INFO from :engine. Is it running?', ['engine' => $row->engine, 'name' => $row->name]);

            return;
        }

        $previous = end($this->keyspaceSamples) ?: null;
        $sample = $sampler->sample($raw, $previous ?: null);

        $this->keyspaceSamples[] = $sample;
        if (count($this->keyspaceSamples) > self::KEYSPACE_SAMPLE_LIMIT) {
            $this->keyspaceSamples = array_slice(
                $this->keyspaceSamples,
                count($this->keyspaceSamples) - self::KEYSPACE_SAMPLE_LIMIT,
            );
        }

        $this->keyspaceLoaded = true;
        $this->keyspaceError = null;
    }

    public function pollKeyspaceDashboard(CacheServiceStats $stats, CacheServiceKeyspaceSampler $sampler): void
    {
        // Same call as load, but silent on failure — a poll tick shouldn't toast.
        try {
            $this->loadKeyspaceDashboard($stats, $sampler);
        } catch (\Throwable) {
            // swallow
        }
    }

    public function hideKeyspaceDashboard(): void
    {
        $this->keyspaceSamples = [];
        $this->keyspaceLoaded = false;
        $this->keyspaceError = null;
    }

    public function flushCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine to flush.', ['engine' => $engine]));

            return;
        }

        if ($row->status !== ServerCacheService::STATUS_RUNNING) {
            $this->toastError(__(':engine must be running to flush. Start it first.', ['engine' => $engine]));

            return;
        }

        $cmd = match ($row->engine) {
            'memcached' => "(printf 'flush_all\\nquit\\n' | timeout 5 nc -q 1 127.0.0.1 ".(int) $row->port.') 2>&1',
            'valkey' => '(command -v valkey-cli >/dev/null && valkey-cli -p '.(int) $row->port.' FLUSHALL) || redis-cli -p '.(int) $row->port.' FLUSHALL',
            'keydb' => '(command -v keydb-cli >/dev/null && keydb-cli -p '.(int) $row->port.' FLUSHALL) || redis-cli -p '.(int) $row->port.' FLUSHALL',
            default => 'redis-cli -p '.(int) $row->port.' FLUSHALL',
        };

        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:flush:'.$row->engine,
                $cmd,
                timeoutSeconds: 30,
                asRoot: false,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(trim($output->buffer) ?: 'Flush command failed.');
            }

            $audit->record(
                $row->server,
                ServerCacheServiceAuditEvent::EVENT_FLUSHED,
                ['engine' => $row->engine, 'name' => $row->name],
                auth()->user(),
            );
            $this->forgetStats($row);

            $this->toastSuccess(__('Flushed all keys on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /** @internal Reused by restart/stop/start. */
    protected function runSystemctl(
        string $engine,
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
        string $verb,
        ?string $newStatus,
        string $event,
    ): void {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine to :verb.', ['engine' => $engine, 'verb' => $verb]));

            return;
        }

        $service = CacheServiceInstallScripts::systemdServiceFor($row->engine);
        try {
            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:'.$verb.':'.$row->engine,
                'systemctl '.$verb.' '.escapeshellarg($service),
                timeoutSeconds: 60,
                asRoot: true,
            );

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(trim($output->buffer) ?: 'systemctl '.$verb.' failed.');
            }

            if ($newStatus) {
                $row->update(['status' => $newStatus]);
            }

            $audit->record($row->server, $event, ['engine' => $row->engine, 'name' => $row->name], auth()->user());
            $this->forgetStats($row);

            $this->toastSuccess(__(':verb succeeded for :engine.', [
                'verb' => ucfirst($verb),
                'engine' => $row->engine, 'name' => $row->name,
            ]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function render(
        ServerCacheServiceHostCapabilities $capabilitiesService,
        CacheServiceStats $statsService,
    ): View {
        $capabilities = ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];
        try {
            $capabilities = $capabilitiesService->forServer($this->server);
        } catch (\Throwable) {
            // Probe failures (SSH timeout, key issues) leave the per-engine "running" badges off.
        }

        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        if (! in_array($this->workspace_tab, $allowed, true)) {
            $this->workspace_tab = 'overview';
        }

        $services = $this->cacheServices();

        // Pull live stats per-engine when looking at Overview and the engine is RUNNING. The
        // 30s cache inside the stats service keeps repeated renders cheap. With multi-instance,
        // stats are shown per (engine, instance-name) — the overview cards iterate over all
        // installed instances individually.
        $statsByInstance = [];
        if ($this->workspace_tab === 'overview') {
            foreach ($services as $row) {
                if ($row->status === ServerCacheService::STATUS_RUNNING) {
                    $statsByInstance[$row->engine][$row->name] = $statsService->snapshot($this->server, $row);
                }
            }
        }

        // Group instances under (engine, name) so the per-engine panel can find the
        // row for the currently-active instance and the chip row can iterate over
        // all installed instances of an engine. The legacy `cacheServicesByEngine`
        // keyed map is preserved for the engine-level tab badge ("Running"/"Failed")
        // — it picks any running instance, which is what we want for that badge.
        $instancesByEngine = $services->groupBy('engine')->map(fn ($group) => $group->keyBy('name'));

        // For tab-strip badges: prefer a running instance, fall back to any.
        $primaryByEngine = collect();
        foreach (CacheServiceInstallScripts::supportedEngines() as $engine) {
            $group = $instancesByEngine->get($engine, collect());
            if ($group->isEmpty()) {
                continue;
            }
            $running = $group->first(fn ($r) => $r->status === ServerCacheService::STATUS_RUNNING);
            $primaryByEngine[$engine] = $running ?? $group->first();
        }

        $auditEvents = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        return view('livewire.servers.workspace-caches', [
            'capabilities' => $capabilities,
            'cacheServices' => $services,
            'cacheInstancesByEngine' => $instancesByEngine,
            'cacheServicesByEngine' => $primaryByEngine,
            'cacheStatsByInstance' => $statsByInstance,
            'cacheAuditEvents' => $auditEvents,
            'engineLabels' => [
                'redis' => 'Redis',
                'valkey' => 'Valkey',
                'memcached' => 'Memcached',
                'keydb' => 'KeyDB',
                'dragonfly' => 'Dragonfly',
            ],
            'engineDescriptions' => [
                'redis' => __('In-memory data structure store; the most widely-deployed cache for PHP/Laravel apps.'),
                'valkey' => __('Open-source fork of Redis maintained by the Linux Foundation; wire-compatible with Redis clients.'),
                'memcached' => __('Lightweight key-value cache. Smaller feature set than Redis but very low overhead.'),
                'keydb' => __('Multi-threaded Redis fork. Higher throughput on multi-core boxes; same wire protocol as Redis.'),
                'dragonfly' => __('Modern in-memory store with Redis wire compatibility and lower memory overhead.'),
            ],
            'deletionSummary' => null,
        ]);
    }

    /** All cache service rows for this server, keyed by ULID and ordered by engine name. */
    protected function cacheServices(): Collection
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->orderBy('engine')
            ->get();
    }

    /**
     * Look up the engine row for the current `$active_instance`. With multi-
     * instance, this is the row every per-engine action operates on. The
     * default value (`'default'`) means existing single-instance servers
     * continue to behave exactly as before.
     */
    protected function cacheServiceFor(string $engine): ?ServerCacheService
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', $this->active_instance)
            ->first();
    }

    /**
     * All instances of an engine on this server, ordered with the legacy
     * `default` first and the rest by name. Used by the per-engine tab to
     * render the chip row that lets the operator switch between instances.
     */
    protected function instancesFor(string $engine): Collection
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->orderByRaw("CASE WHEN name = '".ServerCacheService::DEFAULT_INSTANCE_NAME."' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
    }

    /** The engine name when the operator is on a per-engine tab; null otherwise. */
    protected function currentEngineTab(): ?string
    {
        return in_array($this->workspace_tab, CacheServiceInstallScripts::supportedEngines(), true)
            ? $this->workspace_tab
            : null;
    }

    /** Validate caller-supplied engine name against the supported list. Toasts + returns false on miss. */
    protected function validateEngine(string $engine): bool
    {
        if (! in_array($engine, CacheServiceInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported cache engine.'));

            return false;
        }

        return true;
    }

    /** Wipe the lazy-loaded per-engine UI buffers. Called on tab change. */
    protected function resetPerEngineUiState(): void
    {
        $this->cacheConfigContent = null;
        $this->cacheConfigPath = null;
        $this->cacheConfigError = null;
        $this->cacheConfigEditing = false;
        $this->cacheConfigDraft = '';
        $this->cacheClients = null;
        $this->cacheClientsError = null;
        $this->cacheMemoryLoaded = false;
        $this->cache_maxmemory = '';
        $this->cache_maxmemory_policy = 'noeviction';
        $this->cacheMemoryError = null;
        $this->new_auth_password = '';

        // REPL + dashboard are scoped to the current engine, so their buffers go
        // away on tab change. The unlock toggle deliberately does NOT reset on
        // tab change — it resets on remount, matching the existing
        // "session-scoped trust" pattern in this component.
        $this->replInput = '';
        $this->replHistory = [];
        $this->keyspaceSamples = [];
        $this->keyspaceLoaded = false;
        $this->keyspaceError = null;
    }

    /**
     * Append an entry to the REPL ring buffer and trim from the front if we've
     * exceeded the cap.
     */
    protected function pushReplEntry(string $command, string $output, int $exitCode, string $kind): void
    {
        $this->replHistory[] = [
            'ts' => now()->toIso8601String(),
            'cmd' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'kind' => $kind,
        ];

        if (count($this->replHistory) > self::REPL_HISTORY_LIMIT) {
            $this->replHistory = array_slice(
                $this->replHistory,
                count($this->replHistory) - self::REPL_HISTORY_LIMIT,
            );
        }
    }

    /** @internal Drop the stats cache for a row's engine. */
    private function forgetStats(?ServerCacheService $row): void
    {
        if ($row === null) {
            return;
        }
        app(CacheServiceStats::class)->forget($row->server, $row->engine);
    }
}
