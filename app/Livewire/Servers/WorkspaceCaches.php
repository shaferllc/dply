<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\SwitchCacheServiceJob;
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
use App\Support\Servers\CacheServiceConfigWriter;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceMemoryConfig;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Contracts\View\View;
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
     * Lazy-loaded snapshot of the engine's main config file. Set by `loadCacheConfig()`,
     * cleared by `hideCacheConfig()`. Stored on the component so the operator can scroll
     * through it without it disappearing on the next Livewire roundtrip.
     */
    public ?string $cacheConfigContent = null;

    public ?string $cacheConfigPath = null;

    public ?string $cacheConfigError = null;

    /** True when the operator clicked Edit; the textarea binds to `$cacheConfigDraft`. */
    public bool $cacheConfigEditing = false;

    public string $cacheConfigDraft = '';

    /** Form input for setting the AUTH password on the active redis-family engine. */
    public string $new_auth_password = '';

    /**
     * Lazy-loaded snapshot of `CLIENT LIST` for redis-family engines. Set by `loadCacheClients()`,
     * cleared by `hideCacheClients()`. Stored as a flat array of rows so the Blade can render it
     * without re-running the SSH probe on every Livewire roundtrip.
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

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    public function refreshCacheCapabilities(ServerCacheServiceHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);
        $this->toastSuccess(__('Rechecked the server for cache services.'));
    }

    /**
     * Queue an install for the requested engine. The Phase 1 invariant is one cache service per
     * server, so attempting to install while a different engine is already tracked surfaces a
     * clear toast pointing at the uninstall flow rather than silently swapping.
     */
    public function installCacheService(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($engine, CacheServiceInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported cache engine.'));

            return;
        }

        $existing = $this->activeCacheService();
        if ($existing && $existing->engine !== $engine) {
            $this->toastError(__('Uninstall :current before installing :new — only one cache service is supported per server.', [
                'current' => $existing->engine,
                'new' => $engine,
            ]));

            return;
        }

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
            $this->toastError(__('Install is already in progress or the engine is running.'));

            return;
        }

        InstallCacheServiceJob::dispatch($row->id);
        $this->forgetStats($row);
        $this->toastSuccess(__('Installing :engine — refresh in a moment to see status.', ['engine' => $engine]));
        $this->workspace_tab = $engine;
    }

    public function uninstallCacheService(): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to uninstall.'));

            return;
        }

        UninstallCacheServiceJob::dispatch($row->id);
        $this->forgetStats($row);
        $this->toastSuccess(__('Uninstall queued for :engine.', ['engine' => $row->engine]));
    }

    /**
     * Replace the active cache engine with a different one in a single queued operation.
     * Carries the AUTH password and maxmemory settings forward when both old and new engines
     * support them (i.e. all redis-family pairs). The job updates the row in place so the
     * audit history stays attached to one record.
     */
    public function switchCacheService(string $newEngine): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($newEngine, CacheServiceInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported cache engine.'));

            return;
        }

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service installed yet — use Install instead of Switch.'));

            return;
        }

        if ($row->engine === $newEngine) {
            $this->toastError(__(':engine is already the active cache.', ['engine' => $newEngine]));

            return;
        }

        if (! in_array($row->status, [
            ServerCacheService::STATUS_RUNNING,
            ServerCacheService::STATUS_STOPPED,
            ServerCacheService::STATUS_FAILED,
        ], true)) {
            $this->toastError(__('Cache must be running, stopped, or failed before switching. Wait for the current operation to finish.'));

            return;
        }

        SwitchCacheServiceJob::dispatch($row->id, $newEngine);
        $this->forgetStats($row);
        $this->toastSuccess(__('Switching to :engine — refresh in a moment to see status.', ['engine' => $newEngine]));
        $this->workspace_tab = $newEngine;
    }

    public function restartCacheService(ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($executor, $audit, 'restart', null, ServerCacheServiceAuditEvent::EVENT_RESTARTED);
    }

    public function stopCacheService(ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($executor, $audit, 'stop', ServerCacheService::STATUS_STOPPED, ServerCacheServiceAuditEvent::EVENT_STOPPED);
    }

    public function startCacheService(ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl($executor, $audit, 'start', ServerCacheService::STATUS_RUNNING, ServerCacheServiceAuditEvent::EVENT_STARTED);
    }

    /**
     * SSH-cat the engine's main config file and stash the contents on the component for the
     * read-only viewer card. We `head -c 64K` defensively in case an operator has dumped a giant
     * config into the file; bigger configs are still readable, just truncated for the preview.
     */
    public function loadCacheConfig(ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->cacheConfigError = __('No cache service installed.');

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

    /**
     * Switch the config card from read-only to edit mode. Loads the file first if it's not already
     * loaded so the operator always edits the *current* contents and not a stale snapshot.
     */
    public function startEditingCacheConfig(ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        if ($this->cacheConfigContent === null) {
            $this->loadCacheConfig($executor);
            if ($this->cacheConfigContent === null) {
                // load failed; cacheConfigError is already set
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

    /**
     * Apply the textarea draft to the engine's main config file. Runs the
     * backup → write → restart → verify → rollback flow via
     * {@see CacheServiceConfigWriter}; on success refreshes the read-only display
     * with the new content and audits the change.
     */
    public function saveCacheConfig(
        CacheServiceConfigWriter $writer,
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service installed.'));

            return;
        }

        // Light validation up front (mirrors the writer's guards) so the operator gets a Livewire
        // error message before we burn an SSH round trip.
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

        // Refresh the displayed content from the saved draft so the operator sees the live state
        // without another SSH round trip. Exit edit mode and clear the draft.
        $this->cacheConfigContent = $this->cacheConfigDraft;
        $this->cacheConfigEditing = false;
        $this->cacheConfigDraft = '';

        $audit->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
            ['engine' => $row->engine, 'bytes' => strlen((string) $this->cacheConfigContent)],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Config saved and :engine restarted.', ['engine' => $row->engine]));
    }

    /**
     * Pull `CLIENT LIST` for the active redis-family engine and stash the parsed rows on the
     * component. Memcached is rejected at the model layer (no native equivalent) so this action
     * is hidden in the UI for that engine; the guard is defensive.
     */
    public function loadCacheClients(CacheServiceStats $stats): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->cacheClientsError = __('No cache service installed.');

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->cacheClientsError = __(':engine has no CLIENT LIST equivalent.', ['engine' => $row->engine]);

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

    /**
     * Pull the live `maxmemory` and `maxmemory-policy` values from the engine's main config and
     * pre-fill the form. Memcached has different memory mechanics so it's rejected up front; the
     * UI also hides the card on memcached.
     */
    public function loadCacheMemorySettings(CacheServiceMemoryConfig $memory): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->cacheMemoryError = __('No cache service installed.');

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->cacheMemoryError = __(':engine has no maxmemory directive — memory limits are tuned via systemd or the engine launch flags.', ['engine' => $row->engine]);

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

    /**
     * Apply the form values to the engine's config + restart + verify. The helper handles the
     * atomic flow with rollback. Emits an audit event with the engine + new values so the
     * Advanced-tab log shows what changed.
     */
    public function saveCacheMemorySettings(
        CacheServiceMemoryConfig $memory,
        CacheServiceAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to update.'));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine does not support maxmemory.', ['engine' => $row->engine]));

            return;
        }

        $this->validate([
            // Empty string = "remove the directive". Otherwise the helper rejects unparseable
            // values via guardValues(); duplicating the regex here gives the operator a faster
            // error on the form before we burn an SSH round-trip.
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
                'engine' => $row->engine,
                'maxmemory' => $maxmemory ?: null,
                'maxmemory_policy' => $policy ?: null,
            ],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Memory settings applied to :engine.', ['engine' => $row->engine]));
    }

    /** Pre-fill the AUTH form with a random 32-char password. Operator can edit before submitting. */
    public function generateAuthPassword(): void
    {
        $this->new_auth_password = Str::password(32, symbols: false);
    }

    /**
     * Apply (or rotate) the engine's `requirepass`. Persists the password encrypted on the row so
     * the connection-snippet UI can render it back to the operator without another SSH probe.
     * Memcached is rejected at the model layer.
     */
    public function setAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to authenticate.'));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine]));

            return;
        }

        // Validate against a safe charset before letting it near the shell. The bash payload
        // base64-encodes anyway, but a generous character whitelist keeps copy-paste passwords
        // sane (and stops a lone newline from sneaking through the form).
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
            ['engine' => $row->engine],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('AUTH password set on :engine.', ['engine' => $row->engine]));
    }

    /** Strip `requirepass` from the engine's config and clear the stored password. */
    public function clearAuthPassword(CacheServiceAuth $auth, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to update.'));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no native AUTH password.', ['engine' => $row->engine]));

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
            ['engine' => $row->engine],
            auth()->user(),
        );
        $this->forgetStats($row);

        $this->toastSuccess(__('Cleared AUTH password on :engine.', ['engine' => $row->engine]));
    }

    public function flushCacheService(ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to flush.'));

            return;
        }

        if ($row->status !== ServerCacheService::STATUS_RUNNING) {
            $this->toastError(__('Cache must be running to flush. Start it first.'));

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
                ['engine' => $row->engine],
                auth()->user(),
            );
            $this->forgetStats($row);

            $this->toastSuccess(__('Flushed all keys on :engine.', ['engine' => $row->engine]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Shared implementation for restart/stop/start. Sync (not queued) because systemctl returns
     * within ~1s in practice and the operator's UI feedback is "the action happened".
     */
    /** @internal Helper used by runSystemctl + flush/auth/memory/config savers to drop the stats cache. */
    private function forgetStats(?ServerCacheService $row): void
    {
        if ($row === null) {
            return;
        }
        app(CacheServiceStats::class)->forget($row->server, $row->engine);
    }

    protected function runSystemctl(
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
        string $verb,
        ?string $newStatus,
        string $event,
    ): void {
        $this->authorize('update', $this->server);

        $row = $this->activeCacheService();
        if (! $row) {
            $this->toastError(__('No cache service to :verb.', ['verb' => $verb]));

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

            $audit->record($row->server, $event, ['engine' => $row->engine], auth()->user());
            $this->forgetStats($row);

            $this->toastSuccess(__(':verb succeeded for :engine.', [
                'verb' => ucfirst($verb),
                'engine' => $row->engine,
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
            // The operator can still queue install/uninstall — those flow through the queued jobs
            // and surface their own errors back through the row's status/error_message.
        }

        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        if (! in_array($this->workspace_tab, $allowed, true)) {
            $this->workspace_tab = 'overview';
        }

        $active = $this->activeCacheService();

        // Pull live stats only when (a) the operator is actually looking at the Overview tab — no
        // other tab renders the stats card — and (b) the engine is in the running state. Combined
        // with the 30s cache inside the stats service, this keeps tab switching snappy: most tab
        // changes don't fire any SSH at all.
        $stats = [];
        if ($this->workspace_tab === 'overview' && $active && $active->status === ServerCacheService::STATUS_RUNNING) {
            $stats = $statsService->snapshot($this->server, $active);
        }

        $auditEvents = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        return view('livewire.servers.workspace-caches', [
            'capabilities' => $capabilities,
            'activeCacheService' => $active,
            'cacheStats' => $stats,
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

    protected function activeCacheService(): ?ServerCacheService
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->first();
    }
}
