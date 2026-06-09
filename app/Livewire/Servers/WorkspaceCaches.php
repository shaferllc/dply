<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\TailCacheServiceMonitorJob;
use App\Jobs\UninstallCacheServiceJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Concerns\SurfacesBindingConsumers;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\ServerCacheServiceReplication;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheEngineAvailability;
use App\Support\Servers\CacheEngineInfo;
use App\Support\Servers\CacheServiceAuth;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceCommandPolicy;
use App\Support\Servers\CacheServiceConfigWriter;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceKeyExplorer;
use App\Support\Servers\CacheServiceKeyspaceSampler;
use App\Support\Servers\CacheServiceMemoryConfig;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\CacheServicePersistence;
use App\Support\Servers\CacheServicePort;
use App\Support\Servers\CacheServiceReplicaSetup;
use App\Support\Servers\CacheServiceReplication;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\CacheWorkspaceViewData;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCaches extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.caches';

    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsAllowlistedManageAction;
    use RunsServerConsoleActions;
    use SurfacesBindingConsumers;

    /** Active workspace tab. URL-bound so deep links + back/forward work. */
    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $workspace_tab = 'overview';

    /**
     * Engine reachability + distro-support gates, SSH-probed off the render path
     * (wire:init → loadCacheCapabilities) so the workspace paints instantly.
     * $capabilitiesLoaded gates the "checking…" UI.
     *
     * @var array<string, bool>
     */
    public array $capabilities_state = [];

    /** @var array<string, string|null> */
    public array $cache_unsupported_reasons = [];

    public bool $capabilitiesLoaded = false;

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
    public const ENGINE_SUBTABS = ['overview', 'info', 'console', 'stats', 'configure'];

    /**
     * Active instance name within the current per-engine tab. Historically URL-bound so deep
     * links to a named instance worked; with multi-instance retired (one row per engine, name
     * always `'default'`) this stays as a const-shaped property so legacy reads
     * (`$row->name === $this->active_instance`) keep working without rewriting every call site.
     */
    public string $active_instance = ServerCacheService::DEFAULT_INSTANCE_NAME;

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

    /** Form input for changing the listen port of the active instance on the current tab. */
    public ?int $new_port = null;

    /** Form input for the network-exposure flow's source CIDR (e.g. "10.0.0.0/8"). */
    public string $expose_source_cidr = '';

    /**
     * Lazy-loaded snapshot of `CLIENT LIST` for redis-family engines.
     *
     * @var list<array{id: string, addr: string, name: string, age: string, idle: string, db: string}>|null
     */
    public ?array $cacheClients = null;

    public ?string $cacheClientsError = null;

    /**
     * True when {@see $cacheClients} was hydrated from the result cache on
     * mount/tab-switch rather than from a fresh worker write this session.
     * The view shows a "showing cached snapshot" banner while this is set;
     * the next poll tick that lands a job result clears it.
     */
    public bool $cacheClientsFromCache = false;

    /** ISO8601 timestamp of the cached payload's `at` field, surfaced in the banner. */
    public ?string $cacheClientsCachedAt = null;

    /**
     * Slowlog entries for the Stats subtab card, populated by {@see loadSlowlog}.
     * Null until first load; `[]` when the engine returns an empty ring buffer
     * (the operationally happy case — no commands have crossed the slowlog
     * threshold). Errors surface via $slowlogError.
     *
     * @var list<array{id: int, at: CarbonImmutable, duration_us: int, command: string, client_addr: string, client_name: string}>|null
     */
    public ?array $slowlogEntries = null;

    public ?string $slowlogError = null;

    /** See {@see $cacheClientsFromCache} — same pattern for the slowlog ring buffer. */
    public bool $slowlogFromCache = false;

    public ?string $slowlogCachedAt = null;

    /**
     * Page size + current page for the CLIENT LIST table. Pagination is
     * client-side (we already have the full list in memory) so prev/next
     * doesn't re-SSH — the snapshot is cheap to slice. Reset to page 1 on
     * every fresh `loadCacheClients` so a refresh doesn't strand the operator
     * on a page that no longer exists.
     */
    public const CACHE_CLIENTS_PAGE_SIZE = 10;

    public int $cacheClientsPage = 1;

    /** True after `loadCacheMemorySettings` populates the form below. */
    public bool $cacheMemoryLoaded = false;

    public string $cache_maxmemory = '';

    public string $cache_maxmemory_policy = 'noeviction';

    public ?string $cacheMemoryError = null;

    /** See {@see $cacheClientsFromCache} — same pattern for the memory-settings card. */
    public bool $cacheMemoryFromCache = false;

    public ?string $cacheMemoryCachedAt = null;

    /**
     * Live persistence state for the Configure-subtab card (RDB save schedule,
     * AOF status, last save time, BGSAVE in progress). Null until first load via
     * {@see loadPersistenceState}. Errors surface via $persistenceError.
     *
     * @var array{
     *     reachable: bool,
     *     aof_enabled: ?bool,
     *     aof_size_bytes: ?int,
     *     aof_last_rewrite_at: ?CarbonImmutable,
     *     rdb_last_save_at: ?CarbonImmutable,
     *     rdb_bgsave_in_progress: ?bool,
     *     save_schedule: list<array{seconds: int, changes: int}>,
     *     raw_save: ?string,
     * }|null
     */
    public ?array $persistenceState = null;

    public ?string $persistenceError = null;

    /**
     * Live replication state for the Stats-subtab card: role (master/replica),
     * connected replicas, master link status if this engine is a replica.
     * Loaded lazily via wire:init on the Stats subtab; refreshed by wire:poll.
     *
     * @var array<string, mixed>|null
     */
    public ?array $replicationState = null;

    public ?string $replicationError = null;

    /** See {@see $cacheClientsFromCache} — same pattern for the replication snapshot. */
    public bool $replicationFromCache = false;

    public ?string $replicationCachedAt = null;

    /** Modal: candidate replica server picker (server_id of target). */
    public string $addReplicaTargetServerId = '';

    /** Modal: operator-confirmed wipe of target if it has keys. */
    public bool $addReplicaWipeAcknowledged = false;

    /**
     * Form input for the CACHE_PREFIX editor on the Connection Details card.
     * Persisted on the row's cache_prefix column via {@see setCachePrefix}.
     * Client-side concern only — Laravel's cache driver prepends this to every
     * key before writing/reading; Redis itself doesn't enforce or know about it.
     * Common values: app slug ("acme_") for single-app, env tag ("prod_cache_")
     * for env separation.
     */
    public string $cache_prefix_input = '';

    /**
     * RDB save-schedule editor input. Space-separated `seconds changes` pairs —
     * e.g. "3600 1 300 100" snapshots every 3600s after 1 change or every 300s
     * after 100 changes. Empty disables RDB entirely. Populated from
     * persistence state on subtab mount.
     */
    public string $rdb_save_schedule = '';

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

    /** See {@see $cacheClientsFromCache} — set when samples come from cache, cleared on first fresh sample. */
    public bool $keyspaceFromCache = false;

    public const KEYSPACE_SAMPLE_LIMIT = 60;

    /** SCAN MATCH pattern. Defaults to `*` so the first scan returns everything. */
    public string $keyBrowserPattern = '*';

    /** Opaque SCAN cursor. `'0'` means a fresh scan; any other value means more pages. */
    public string $keyBrowserCursor = '0';

    /**
     * Accumulated keys from one or more SCAN pages. Operators see the full list
     * and "Load more" continues from the last cursor.
     *
     * @var list<string>
     */
    public array $keyBrowserKeys = [];

    public bool $keyBrowserLoaded = false;

    public ?string $keyBrowserError = null;

    /** True when the last SCAN page reported cursor=0 (no more keys to fetch). */
    public bool $keyBrowserComplete = false;

    /**
     * Client-side pagination of the in-memory key list. The SCAN buffer can
     * accumulate hundreds of keys across "Load more" presses; rendering them
     * all at once creates a scrolling wall — paginating in slices of 25 keeps
     * the result table readable and prev/next is free (just an array slice).
     */
    public const KEYS_TABLE_PAGE_SIZE = 25;

    public int $keysTablePage = 1;

    /**
     * Marker set when we hydrated keys from the user's session on mount —
     * indicates the data is from a previous visit, not a fresh SCAN. The
     * card shows a soft "from cache · Search to refresh" banner so the
     * operator knows what they're looking at without leaving the page blank
     * waiting for them to re-run Search.
     */
    public bool $keyBrowserFromCache = false;

    public ?string $keyBrowserSelected = null;

    /** @var array{type: string, ttl: int, value: string|list<string>, truncated: bool}|null */
    public ?array $keyBrowserValue = null;

    public ?string $keyBrowserValueError = null;

    /**
     * Active MONITOR run ID. Empty string when no MONITOR is in flight; a ULID
     * while a tail is running. The Blade reads this to decide whether to poll
     * the cache buffer for output.
     */
    public string $monitorRunId = '';

    /** Operator-chosen MONITOR window (5/10/30 s). Bounded server-side too. */
    public int $monitorDurationSeconds = 10;

    /**
     * Latest snapshot of the MONITOR cache buffer. Populated by the 1s poll
     * while a run is in flight. Shape mirrors what `TailCacheServiceMonitorJob`
     * writes — `status`, `lines`, `error`.
     *
     * @var array{status: string, lines: list<string>, error: ?string}|null
     */
    public ?array $monitorPayload = null;

    /**
     * Status/Logs modal for the active cache instance. Lets operators inspect a
     * specific instance's systemd state without dropping to SSH. Mirrors the
     * shape of the Services workspace status modal, but scoped to caches so we
     * don't drag in the unrelated state of `ManagesServerSystemdServices`.
     * Properties are cache-prefixed in case both concerns ever co-exist.
     */
    public bool $showCacheStatusModal = false;

    public string $cacheStatusModalEngine = '';

    public string $cacheStatusModalInstance = '';

    public string $cacheStatusModalUnit = '';

    /** Either 'status' (systemctl status) or 'logs' (journalctl -u …). */
    public string $cacheStatusModalView = 'status';

    public string $cacheStatusModalOutput = '';

    public bool $cacheStatusModalLoading = false;

    public ?string $cacheStatusModalError = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->hydrateKeyBrowserFromSession();
        $this->hydrateKeyspaceSamplesFromCache();
        $this->hydrateCacheStatsFromResultCache();
    }

    /**
     * Read the last-known clients / slowlog / replication snapshots out of the
     * refresh-job result cache so the Stats subtab lands populated instead of
     * showing "No snapshot yet" until the first poll tick completes. The
     * accompanying `*FromCache` flags drive a "showing cached — refreshing in
     * the background" banner on each card; the next poll tick that lands a
     * fresh worker write clears them.
     */
    protected function hydrateCacheStatsFromResultCache(): void
    {
        $engine = $this->workspace_tab;
        if (! in_array($engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row || ! $row->server) {
            return;
        }

        $clients = Cache::get(\App\Jobs\RefreshCacheClientsJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($clients) && ($clients['ok'] ?? false) === true) {
            $this->cacheClients = array_values(array_filter((array) ($clients['clients'] ?? []), 'is_array'));
            $this->cacheClientsFromCache = true;
            $this->cacheClientsCachedAt = isset($clients['at']) ? (string) $clients['at'] : null;
        }

        $slowlog = Cache::get(\App\Jobs\RefreshSlowlogJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($slowlog) && ($slowlog['ok'] ?? false) === true) {
            $this->slowlogEntries = array_values(array_filter((array) ($slowlog['entries'] ?? []), 'is_array'));
            $this->slowlogFromCache = true;
            $this->slowlogCachedAt = isset($slowlog['at']) ? (string) $slowlog['at'] : null;
        }

        $replication = Cache::get(\App\Jobs\RefreshReplicationStateJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($replication) && ($replication['ok'] ?? false) === true) {
            $this->replicationState = is_array($replication['state'] ?? null) ? $replication['state'] : null;
            $this->replicationFromCache = true;
            $this->replicationCachedAt = isset($replication['at']) ? (string) $replication['at'] : null;
        }

        $memory = Cache::get(\App\Jobs\RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($memory) && ($memory['ok'] ?? false) === true) {
            $this->cache_maxmemory = (string) ($memory['maxmemory'] ?? '');
            $this->cache_maxmemory_policy = (string) ($memory['maxmemory_policy'] ?? 'noeviction');
            $this->cacheMemoryLoaded = true;
            $this->cacheMemoryFromCache = true;
            $this->cacheMemoryCachedAt = isset($memory['at']) ? (string) $memory['at'] : null;
        }
    }

    /**
     * Pull the recent keyspace samples for the active engine out of the cache so
     * the dashboard lands with at least one prior sample on the buffer — that
     * lets the very first ops/sec + hit-rate window compute on the next poll
     * tick instead of showing "—" until two fresh samples accumulate.
     *
     * Cross-user (uses Cache, not session) so the data is "warm" regardless of
     * which operator opens the page. Sampler delta math uses real timestamps,
     * so a stale previous sample produces a correct (just-wider) window.
     */
    protected function hydrateKeyspaceSamplesFromCache(): void
    {
        $key = $this->keyspaceSamplesCacheKey();
        if ($key === null) {
            return;
        }

        $cached = Cache::get($key);
        if (! is_array($cached) || $cached === []) {
            return;
        }

        $this->keyspaceSamples = array_values(array_filter($cached, 'is_array'));
        $this->keyspaceLoaded = $this->keyspaceSamples !== [];
        $this->keyspaceFromCache = $this->keyspaceSamples !== [];
    }

    /**
     * Persist the current keyspace sample buffer to cache so the next page load
     * (any user / any session) can re-hydrate it. Scoped per (server, engine)
     * so engine tabs don't bleed into each other.
     */
    protected function persistKeyspaceSamplesToCache(): void
    {
        $key = $this->keyspaceSamplesCacheKey();
        if ($key === null) {
            return;
        }

        if ($this->keyspaceSamples === []) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $this->keyspaceSamples, now()->addHour());
    }

    /**
     * Cache key namespace for keyspace samples — per (server, engine) so
     * switching engines doesn't carry the wrong buffer across.
     */
    protected function keyspaceSamplesCacheKey(): ?string
    {
        $engine = $this->workspace_tab;
        if (! in_array($engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true)) {
            return null;
        }

        return sprintf('dply.cache_workspace.keyspace_samples.%s.%s', $this->server->id, $engine);
    }

    /**
     * Pull the most-recent key browser snapshot out of the user's session if
     * one exists for this server + engine. Keeps the table populated on page
     * reload / back-button so the operator never lands on an empty card and
     * has to re-run Search just to remind themselves what they were looking
     * at. The `keyBrowserFromCache` flag drives a "click Search to refresh"
     * banner in the view.
     */
    protected function hydrateKeyBrowserFromSession(): void
    {
        $key = $this->keyBrowserSessionKey();
        if ($key === null) {
            return;
        }

        $snapshot = session($key);
        if (! is_array($snapshot) || empty($snapshot['keys'])) {
            return;
        }

        $this->keyBrowserKeys = array_values(array_filter((array) $snapshot['keys'], 'is_string'));
        $this->keyBrowserCursor = (string) ($snapshot['cursor'] ?? '0');
        $this->keyBrowserComplete = (bool) ($snapshot['complete'] ?? false);
        $this->keyBrowserPattern = (string) ($snapshot['pattern'] ?? ($this->keyBrowserPattern ?: '*'));
        $this->keyBrowserLoaded = true;
        $this->keyBrowserFromCache = true;
        $this->keysTablePage = 1;
    }

    /**
     * Persist the current key-browser state into the session so the next
     * page load (mount) can re-hydrate. Scoped to the workspace_tab (engine)
     * so switching engines doesn't carry the wrong list across.
     */
    protected function persistKeyBrowserToSession(): void
    {
        $key = $this->keyBrowserSessionKey();
        if ($key === null) {
            return;
        }

        session([$key => [
            'keys' => array_values(array_filter($this->keyBrowserKeys, 'is_string')),
            'cursor' => $this->keyBrowserCursor,
            'complete' => $this->keyBrowserComplete,
            'pattern' => $this->keyBrowserPattern ?: '*',
            'saved_at' => now()->toIso8601String(),
        ]]);
    }

    /**
     * Session key namespace — per-server + per-engine so listings don't mix.
     * The `v2` version tag forces the previous session payload to be ignored:
     * v1 stored keys that came from `redis-cli --no-raw SCAN`, which wrapped
     * names in `1) "…"` array-index quotes; any inspect attempt on that
     * malformed key returned `none` because the literal `1) "name"` doesn't
     * exist. Bumping the version invalidates those caches on first mount.
     */
    protected function keyBrowserSessionKey(): ?string
    {
        $engine = $this->workspace_tab;
        if (! in_array($engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true)) {
            return null;
        }

        return sprintf('dply.cache_workspace.key_browser_v2.%s.%s', $this->server->id, $engine);
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
        }

        $this->workspace_tab = $next;

        // Re-hydrate the keyspace sample buffer from cache for the new engine
        // tab so the dashboard's ops/sec + hit-rate tiles aren't stuck on "—"
        // until two fresh samples accumulate post-switch.
        $this->hydrateKeyspaceSamplesFromCache();
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
            $this->slowlogEntries = null;
            $this->slowlogError = null;
            $this->persistenceState = null;
            $this->persistenceError = null;
            $this->rdb_save_schedule = '';
            $this->replicationState = null;
            $this->replicationError = null;
            $this->addReplicaTargetServerId = '';
            $this->addReplicaWipeAcknowledged = false;
            $this->cacheMemoryLoaded = false;
            $this->cache_maxmemory = '';
            $this->cache_maxmemory_policy = 'noeviction';
            $this->cacheMemoryError = null;
            $this->new_auth_password = '';
            $this->keyspaceSamples = [];
            $this->keyspaceLoaded = false;
            $this->keyspaceError = null;
            $this->keyBrowserPattern = '*';
            $this->keyBrowserCursor = '0';
            $this->keyBrowserKeys = [];
            $this->keyBrowserLoaded = false;
            $this->keyBrowserComplete = false;
            $this->keyBrowserSelected = null;
            $this->keyBrowserValue = null;
            $this->keyBrowserValueError = null;
            $this->keyBrowserError = null;
            $this->monitorRunId = '';
            $this->monitorPayload = null;
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

    public function refreshCacheCapabilities(
        ServerCacheServiceHostCapabilities $capabilities,
        CacheServiceStats $stats,
    ): void {
        $this->authorize('update', $this->server);

        // Bust every SSH-bound cache the render path touches so the next render pulls fresh
        // data. Capabilities + distro feed the engine badges and install gating; stats feed
        // the per-engine Overview cards. Without busting all three, the operator clicks
        // Refresh and only the badges update — leaving stale memory / hit-rate numbers.
        $capabilities->forget($this->server);
        $capabilities->forgetDistro($this->server);
        foreach (CacheServiceInstallScripts::supportedEngines() as $engine) {
            $stats->forget($this->server, $engine);
        }

        // Re-probe off the render path: clearing the flag makes the wire:init hook
        // re-fire loadCacheCapabilities on the next render rather than blocking here.
        $this->capabilitiesLoaded = false;
        $this->capabilities_state = [];
        $this->cache_unsupported_reasons = [];

        $this->toastSuccess(__('Refreshed cache workspace data from the server.'));
    }

    /**
     * Resolve engine capabilities + distro-support gates off the render path.
     * Fired by wire:init so the workspace paints instantly; the per-engine badge
     * and Install gate appear once the (24h-cached) probe returns.
     */
    public function loadCacheCapabilities(ServerCacheServiceHostCapabilities $capabilities): void
    {
        $this->authorize('view', $this->server);

        try {
            $this->capabilities_state = $capabilities->forServer($this->server);
        } catch (\Throwable) {
            $this->capabilities_state = ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];
        }

        try {
            $this->cache_unsupported_reasons = $capabilities->unsupportedReasonsByEngine($this->server);
        } catch (\Throwable) {
            $this->cache_unsupported_reasons = ['redis' => null, 'valkey' => null, 'memcached' => null, 'keydb' => null, 'dragonfly' => null];
        }

        $this->capabilitiesLoaded = true;
    }

    /**
     * Stream output from an SSH-executor result into the given ConsoleEmitter
     * line-by-line, preserving exit semantics. Each non-empty line becomes one
     * entry; the source defaults to 'cache' so the partial's per-source colouring
     * groups cache-engine output together. Throws on non-zero exit so the
     * {@see runConsoleAction()} wrapper marks the row failed and re-throws.
     */
    protected function emitExecutorBuffer(ConsoleEmitter $emit, string $buffer, int $exitCode, string $verb): void
    {
        foreach (preg_split("/\r?\n/", $buffer) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'cache');
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(__(':verb failed (exit :code).', [
                'verb' => ucfirst($verb), 'code' => $exitCode,
            ]));
        }
    }

    /**
     * Re-probe THIS instance with the correct port and refresh the cached
     * capability bitmap. Surfaces a toast with the result so the operator
     * doesn't have to chase the badge to verify. Cheaper than installing /
     * uninstalling something to bust the cache.
     */
    public function recheckCacheServiceInstance(string $engine): void
    {
        $this->authorize('update', $this->server);
        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if ($row === null) {
            $this->toastError(__('No :engine instance to recheck.', ['engine' => $engine]));

            return;
        }

        // Two-step pattern so the banner appears IMMEDIATELY in QUEUED state
        // and the operator sees progress as the job runs — instead of clicking
        // and staring at a still page for 2-3s while a synchronous SSH probe
        // blocks the response. seedConsoleActionRun() creates the row up
        // front; the queued job flips it through RUNNING → COMPLETED while
        // emitting probe output. wire:poll on the banner picks up each state
        // transition.
        $consoleActionId = $this->seedConsoleActionRun(
            $row,
            'cache_recheck',
            __('Recheck :engine instance :name on :host', [
                'engine' => $engine, 'name' => $row->name, 'host' => $this->server->name,
            ])
        );

        \App\Jobs\RecheckCacheServiceJob::dispatch($consoleActionId, $row->id);
    }

    /**
     * Run a small diagnostic script on the server and surface the output on
     * the page so the operator can see *why* the instance isn't reachable.
     * Three pieces, all best-effort (each `|| true` so one missing tool doesn't
     * abort the others):
     *   1. `systemctl status <unit>` — is the daemon actually up?
     *   2. `ss -tlnp | grep :<port>` — is something listening on that port?
     *   3. `<engine>-cli -p <port> ping` — does it respond to RESP / proto?
     * Output is truncated server-side so a long log doesn't bloat the wire.
     */
    public function debugCacheServiceInstance(
        string $engine,
        ExecuteRemoteTaskOnServer $executor,
    ): void {
        $this->authorize('update', $this->server);
        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if ($row === null) {
            $this->toastError(__('No :engine instance to debug.', ['engine' => $engine]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('SSH must be ready before running diagnostics.'));

            return;
        }

        $unit = CacheServiceInstallScripts::instanceServiceUnit($engine, $row->name);
        $port = (int) $row->port;
        // CLI tool: each engine's own cli first; redis-cli as the RESP fallback.
        $cli = match ($engine) {
            'redis' => 'redis-cli',
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => '',
        };

        $script = sprintf(
            <<<'BASH'
echo "═══ systemctl status %1$s ═══"
systemctl status --no-pager --lines=20 %1$s 2>&1 || true
echo
echo "═══ Listening on :%2$d? ═══"
(ss -tlnp 2>/dev/null | grep ":%2$d " || netstat -tlnp 2>/dev/null | grep ":%2$d " || echo "Nothing listening on :%2$d (or ss/netstat unavailable).") || true
echo
%3$s
echo "═══ Recent journal (last 30 lines) ═══"
journalctl -u %1$s --no-pager -n 30 2>&1 || true
BASH,
            escapeshellarg($unit),
            $port,
            $cli !== ''
                ? sprintf("echo \"═══ %1\$s -p %2\$d ping ═══\"\n(command -v %1\$s >/dev/null && %1\$s -p %2\$d ping 2>&1 || (command -v redis-cli >/dev/null && redis-cli -p %2\$d ping 2>&1) || echo \"%1\$s and redis-cli not on PATH\") || true\necho",
                    $cli, $port)
                : '',
        );

        try {
            $this->runConsoleAction(
                $row,
                'cache_debug',
                __('Debug :engine instance :name on :host', [
                    'engine' => $engine, 'name' => $row->name, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($executor, $engine, $row, $script): void {
                    $output = $executor->runInlineBash(
                        $this->server,
                        'cache-service:debug:'.$engine.':'.$row->name,
                        $script,
                        timeoutSeconds: 30,
                        asRoot: true,
                    );
                    // Diagnostic script is intentionally `|| true`-guarded throughout, so
                    // exit 0 is the norm even when a probe finds nothing. We surface the
                    // whole buffer regardless and never treat it as a failure here.
                    $this->emitExecutorBuffer($emit, $output->buffer, 0, 'debug');
                },
            );
            $this->toastSuccess(__('Diagnostics captured. See the console banner at the top of the page.'));
        } catch (\Throwable $e) {
            $this->toastError(__('Diagnostic run failed — see the console banner at the top for details.'));
        }
    }

    /**
     * Trigger a systemctl-status probe for the active instance. Now routes
     * through the queued StatusCacheServiceJob so the result lands in the
     * top-of-page console banner — same pattern as Recheck / Debug. Replaces
     * the previous modal flow; the operator sees the result inline with every
     * other action and can hit "Open" on the banner to expand the full output.
     */
    public function showCacheInstanceStatus(string $engine): void
    {
        $this->dispatchCacheStatusJob($engine, 'status');
    }

    /**
     * Trigger a journalctl -u probe for the active instance — same banner-based
     * flow as `showCacheInstanceStatus`.
     */
    public function showCacheInstanceLogs(string $engine): void
    {
        $this->dispatchCacheStatusJob($engine, 'logs');
    }

    /**
     * Shared seed+dispatch path for Status and Logs. Seeds a ConsoleAction row
     * up front (banner appears in QUEUED) and dispatches the queued job which
     * flips through RUNNING → COMPLETED while emitting output lines.
     */
    protected function dispatchCacheStatusJob(string $engine, string $view): void
    {
        $this->authorize('update', $this->server);
        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if ($row === null) {
            $this->toastError(__('No :engine instance to inspect.', ['engine' => $engine]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('SSH must be ready before viewing status or logs.'));

            return;
        }

        $kind = $view === 'logs' ? 'cache_logs' : 'cache_status';
        $label = $view === 'logs'
            ? __('Logs for :engine instance :name on :host', ['engine' => $engine, 'name' => $row->name, 'host' => $this->server->name])
            : __('Status of :engine instance :name on :host', ['engine' => $engine, 'name' => $row->name, 'host' => $this->server->name]);

        $consoleActionId = $this->seedConsoleActionRun($row, $kind, $label);
        \App\Jobs\StatusCacheServiceJob::dispatch($consoleActionId, $row->id, $view);
    }

    /**
     * Switch between the Status and Logs tabs inside the open modal. Only
     * re-probes when the view actually changes — clicking the active tab is a
     * no-op (Refresh is for re-running the same probe).
     */
    public function setCacheStatusModalView(string $view, ExecuteRemoteTaskOnServer $executor): void
    {
        if (! in_array($view, ['status', 'logs'], true)) {
            return;
        }
        if ($view === $this->cacheStatusModalView) {
            return;
        }

        $this->cacheStatusModalView = $view;
        $this->runCacheStatusProbe($executor);
    }

    /** Re-run the probe for whichever view (status/logs) the modal currently shows. */
    public function refreshCacheStatusModal(ExecuteRemoteTaskOnServer $executor): void
    {
        if (! $this->showCacheStatusModal) {
            return;
        }
        $this->runCacheStatusProbe($executor);
    }

    public function closeCacheStatusModal(): void
    {
        $this->showCacheStatusModal = false;
        $this->cacheStatusModalEngine = '';
        $this->cacheStatusModalInstance = '';
        $this->cacheStatusModalUnit = '';
        $this->cacheStatusModalView = 'status';
        $this->cacheStatusModalOutput = '';
        $this->cacheStatusModalLoading = false;
        $this->cacheStatusModalError = null;
    }

    /**
     * Shared open path for the Status and Logs buttons. Authorizes, resolves
     * the active instance row, then triggers the SSH probe. Modal stays open
     * on probe failure so the operator can read the error and hit Refresh.
     */
    protected function openCacheStatusModal(string $engine, string $view, ExecuteRemoteTaskOnServer $executor): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if ($row === null) {
            $this->toastError(__('No :engine instance to inspect.', ['engine' => $engine]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('SSH must be ready before viewing status or logs.'));

            return;
        }

        $this->showCacheStatusModal = true;
        $this->cacheStatusModalEngine = $row->engine;
        $this->cacheStatusModalInstance = $row->name;
        $this->cacheStatusModalUnit = CacheServiceInstallScripts::instanceServiceUnit($row->engine, $row->name);
        $this->cacheStatusModalView = $view === 'logs' ? 'logs' : 'status';

        $this->runCacheStatusProbe($executor);
    }

    /**
     * Inline SSH probe shared by Status and Logs. Uses the same shape as
     * `debugCacheServiceInstance` — 30s timeout, root, 8KB tail cap on the
     * wire payload. Read-only by design, so no audit event (matches the
     * Services workspace status modal).
     */
    protected function runCacheStatusProbe(ExecuteRemoteTaskOnServer $executor): void
    {
        if ($this->cacheStatusModalUnit === '') {
            return;
        }

        $unit = escapeshellarg($this->cacheStatusModalUnit);
        $view = $this->cacheStatusModalView;

        // Same command shape used by the Services status modal — wrapping in
        // `(...); exit 0` so a non-zero systemctl/journalctl exit code (e.g.
        // unit not loaded yet) still surfaces output instead of throwing.
        $script = $view === 'logs'
            ? '(journalctl --no-pager --output=short-iso -u '.$unit.' -n 200 2>&1); exit 0'
            : '(systemctl status '.$unit.' --no-pager -l 2>&1); exit 0';

        $this->cacheStatusModalLoading = true;
        $this->cacheStatusModalError = null;
        $this->cacheStatusModalOutput = '';

        try {
            $output = $executor->runInlineBash(
                $this->server,
                'cache-service:'.$view.':'.$this->cacheStatusModalEngine.':'.$this->cacheStatusModalInstance,
                $script,
                timeoutSeconds: 30,
                asRoot: true,
            );
            $this->cacheStatusModalOutput = trim($output->buffer) !== ''
                ? mb_substr($output->buffer, -8_000)
                : __('No output.');
        } catch (\Throwable $e) {
            $this->cacheStatusModalError = $e->getMessage();
        } finally {
            $this->cacheStatusModalLoading = false;
        }
    }

    /**
     * Queue an install for the requested engine. Multi-engine is now allowed: Redis + Memcached
     * side-by-side is a legit pattern (Redis for queues/Horizon, Memcached for app cache).
     */
    public function installCacheService(string $engine, ServerCacheServiceHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        // Coming-soon gate — Valkey / Memcached / KeyDB / Dragonfly are gated
        // behind cache.{engine} flags until their install path is GA. Refuse
        // before queueing so a stale payload can't slip past the disabled UI.
        if (CacheEngineAvailability::isComingSoon($engine)) {
            $this->toastError(__(':engine isn\'t available yet — it\'s coming soon.', [
                'engine' => CacheEngineInfo::for($engine)['label'] ?? ucfirst($engine),
            ]));

            return;
        }

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        // Distro gate — surface the same message the Install button's UI gate uses so a stale
        // payload can't slip past and queue a job that's guaranteed to fail at apt time.
        $reason = $capabilities->engineUnsupportedReason($this->server, $engine);
        if ($reason !== null) {
            $this->toastError($reason);

            return;
        }

        $existing = $this->cacheServiceFor($engine);

        // Coexistence rule: at most one row per family (redis-family + memcached). Reject before
        // creating a new row when a sibling already occupies this family's slot. The operator's
        // path forward is Uninstall on the existing one, or the engine-switch flow for in-family
        // moves (Redis → Valkey etc.).
        if ($existing === null) {
            $sameFamily = ServerCacheService::query()
                ->where('server_id', $this->server->id)
                ->whereIn('engine', $this->engineFamilyEngines($engine))
                ->first();
            if ($sameFamily !== null) {
                $this->toastError(__(
                    'This server already has :existing installed. Uninstall it first, or use Switch to move within the redis family.',
                    ['existing' => $sameFamily->engine],
                ));

                return;
            }
        }

        $row = $existing ?? ServerCacheService::query()->create([
            'server_id' => $this->server->id,
            'engine' => $engine,
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
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
     * Which engines belong to the same family as `$engine` for the coexistence rule. Mirrors
     * {@see ServerCacheService::familyOf()} — kept here as a small helper so the install action
     * doesn't have to import the family constants directly.
     *
     * @return list<string>
     */
    private function engineFamilyEngines(string $engine): array
    {
        return ServerCacheService::familyOf($engine) === ServerCacheService::FAMILY_REDIS
            ? ServerCacheService::FAMILY_REDIS_ENGINES
            : ['memcached'];
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

            $hasOtherInstances = ServerCacheService::query()
                ->where('server_id', $this->server->id)
                ->where('engine', $engine)
                ->where('id', '!=', $row->id)
                ->exists();

            $this->toastSuccess($hasOtherInstances
                ? __('Cancelling :engine — the job will stop at the next chunk and remove this instance only. The package stays because other :engine instances are still using it; remove them first if you want the package gone.', ['engine' => $engine])
                : __('Cancelling :engine — the job will stop at the next chunk and apt-purge to revert.', ['engine' => $engine])
            );

            return;
        }

        if ($row->status === ServerCacheService::STATUS_UNINSTALLING) {
            $this->toastError(__('Uninstall is already running — wait for it to finish.'));

            return;
        }

        $this->toastError(__('Nothing to cancel: the row is :status.', ['status' => $row->status]));
    }

    /**
     * Hard exit from a stuck "Cancelling — reverting…" state. The standard cancel
     * (above) just flips `cancel_requested_at` and waits for the install job to
     * notice on its next chunk flush — but the check only fires when there's
     * output, so an apt-get install hung on a dpkg lock or an SSH session that
     * stops streaming will never observe the flag.
     *
     * This bypasses the job entirely: marks the row FAILED with a "force-
     * cancelled" reason, records the audit event, and lets the operator move on.
     * The actual on-server state may be partial — the operator runs the engine's
     * uninstall path (apt purge / systemctl disable) themselves if they want a
     * clean revert. UI surfaces this caveat in the button copy + confirm.
     *
     * Available in the UI only after a staleness threshold (60s since cancel
     * was requested) so the normal soft-cancel path gets its chance first.
     *
     * Looks up the row by `engine` + busy-status (not `cacheServiceFor()` which
     * filters by `$active_instance`) — the busy banner is global, so the row to
     * force-cancel may not match the operator's currently-selected per-engine
     * tab. Using the active-instance filter would silently return null and
     * leave the row stuck forever.
     */
    /**
     * Break-glass for an orphaned row: the operator has decided the dply DB row no longer
     * reflects reality and just wants it gone. Deletes the row outright, audits the previous
     * state, and does NOT touch the server. Use this when:
     *  - Uninstall has failed repeatedly (apt purge can't find the package, etc.)
     *  - The install never produced anything on the box (e.g. KeyDB row on Ubuntu noble — there
     *    is no package to clean up)
     *  - The row is in some terminal state the existing affordances refuse to clean up
     *
     * Unlike {@see forceCancelCacheServiceChange()} this is operator-initiated *from the row's
     * own card* (not from the busy banner) and is available for any state, so it's the right
     * affordance for a stuck RUNNING/STOPPED row whose box-side state diverged.
     *
     * Targets the row that matches `engine` + `active_instance` (just like the rest of the
     * per-instance actions on this card) — distinct from forceCancelCacheServiceChange's
     * busy-priority lookup, because the operator's intent here is clearly "this specific
     * instance I'm looking at".
     */
    public function forceRemoveCacheServiceRow(string $engine, CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if ($row === null) {
            $this->toastError(__('No :engine instance to remove.', ['engine' => $engine]));

            return;
        }

        $previousStatus = $row->status;
        $instanceName = $row->name;
        $port = $row->port;

        // Drop the per-engine stats cache BEFORE the delete — after delete the model's relations
        // can re-query and we'd rather avoid a stray select(servers) for a deleted row.
        app(CacheServiceStats::class)->forget($this->server, $row->engine);

        // Delete the row outright. We do NOT call $capabilities->forget() here because the
        // capability probe reads from the box (not the DB) — if KeyDB really isn't installed,
        // the cache invalidation doesn't help; if it IS installed and the operator just wants
        // dply to forget about it, that's their choice and a stale "true" badge will clear on
        // its own 120s TTL.
        $row->delete();

        $audit->record(
            $this->server,
            'force_removed',
            [
                'engine' => $engine,
                'instance' => $instanceName,
                'port' => $port,
                'previous_status' => $previousStatus,
                'reason' => 'operator_orphan_cleanup',
            ],
            auth()->user(),
        );

        // The deleted ServerCacheService row took any banner-attached ConsoleAction
        // rows with it (subject_id no longer resolves), so the per-engine banner
        // disappears on the next render without explicit cleanup.

        $this->toastSuccess(__(':engine instance ":name" removed from dply. Server-side state (binaries, config files, data dirs) was NOT touched — run apt purge / systemctl disable / rm manually if anything needs cleaning up on the box.', [
            'engine' => $engine,
            'name' => $instanceName,
        ]));
    }

    public function forceCancelCacheServiceChange(string $engine, CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        if (! $this->validateEngine($engine)) {
            return;
        }

        // Match whichever instance is actually busy — there's at most one per
        // engine in practice because the install/uninstall jobs hold the dpkg
        // lock serially. If somehow there isn't a busy row, fall back to any
        // row for the engine so the break-glass still cleans up a leftover.
        $row = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->orderByRaw(
                'CASE status '
                ."WHEN 'installing' THEN 0 "
                ."WHEN 'uninstalling' THEN 1 "
                ."WHEN 'pending' THEN 2 "
                ."WHEN 'failed' THEN 3 "
                .'ELSE 9 END'
            )
            ->first();
        if ($row === null) {
            $this->toastError(__('No :engine row found to force-remove.', ['engine' => $engine]));

            return;
        }

        // Refuse only the cleanly-running terminal states — RUNNING/STOPPED
        // have proper affordances (uninstall) and clobbering them via force-
        // cancel would surprise the operator. Everything else (PENDING,
        // INSTALLING, UNINSTALLING, FAILED) can be force-removed; FAILED
        // explicitly included so a leftover row from a prior failed install
        // can be cleared without going through uninstall.
        $protectedStatuses = [
            ServerCacheService::STATUS_RUNNING,
            ServerCacheService::STATUS_STOPPED,
        ];
        if (in_array($row->status, $protectedStatuses, true)) {
            $this->toastError(__('Force-cancel refuses healthy rows (current status: :status). Use Uninstall instead.', ['status' => $row->status]));

            return;
        }

        $previousStatus = $row->status;
        $instanceName = $row->name;

        // Delete the row outright — break-glass means the operator wants a clean
        // slate. We don't keep a FAILED tombstone because the busy-check on
        // other operations would still see the row and the "Cancelling — reverting…"
        // banner would re-render until manually dismissed. Clean break is the right
        // call here; audit captures the previous state for forensics.
        $row->delete();

        $audit->record(
            $this->server,
            'force_cancelled',
            [
                'engine' => $engine,
                'instance' => $instanceName,
                'reason' => 'operator_break_glass',
                'previous_status' => $previousStatus,
            ],
            auth()->user(),
        );

        $this->forgetStats($row);
        $this->toastSuccess(__(':engine row removed. Server-side state may be partial — verify with `dpkg -l | grep :engine` and clean up manually if needed.', ['engine' => $engine]));
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

        $hasSibling = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('id', '!=', $row->id)
            ->exists();

        UninstallCacheServiceJob::dispatch($row->id);
        $this->forgetStats($row);
        $this->toastSuccess($hasSibling
            ? __('Removing instance :name (:engine) — the package stays for the other instances.', ['name' => $row->name, 'engine' => $engine])
            : __('Uninstall queued for :engine — last instance, apt purge is included.', ['engine' => $engine]));
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
     * Disable + stop the engine's systemd unit in one shot — equivalent to `systemctl disable --now`.
     * Differs from {@see stopCacheService()} in that it also clears the unit's boot-time enablement,
     * so the daemon won't come back on the next reboot. Use this when the operator wants the
     * service off for the long haul without uninstalling the package (data dirs + config stay).
     *
     * Reuses runSystemctl's plumbing — same console-output routing, same audit/toast shape. The
     * verb is `disable --now` so the rendered script is `systemctl disable --now <unit>`; runs
     * fine under `set -euo pipefail` because the trailing `systemctl status` short-circuit
     * captures the post-stop state and is `|| true`-guarded.
     */
    public function disableCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl(
            $engine,
            $executor,
            $audit,
            'disable --now',
            ServerCacheService::STATUS_STOPPED,
            ServerCacheServiceAuditEvent::EVENT_STOPPED,
            label: __('Disable'),
        );
    }

    /**
     * Enable + start the engine's systemd unit in one shot — equivalent to `systemctl enable --now`.
     * Companion to {@see disableCacheService()}: re-arms boot-time auto-start AND starts the daemon
     * immediately, so the operator gets one click instead of "Enable, then Start".
     */
    public function enableCacheService(string $engine, ExecuteRemoteTaskOnServer $executor, CacheServiceAuditLogger $audit): void
    {
        $this->runSystemctl(
            $engine,
            $executor,
            $audit,
            'enable --now',
            ServerCacheService::STATUS_RUNNING,
            ServerCacheServiceAuditEvent::EVENT_STARTED,
            label: __('Enable'),
        );
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
            $draft = $this->cacheConfigDraft;
            $this->runConsoleAction(
                $row,
                'cache_save_config',
                __('Save :engine config on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($writer, $row, $draft, $audit): void {
                    $emit->step('cache', sprintf('Writing %d bytes to %s config', strlen($draft), $row->engine));
                    $writer->write($row->server, $row, $draft);
                    $emit->success('cache', 'Config written and engine restarted.');

                    $audit->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
                        ['engine' => $row->engine, 'name' => $row->name, 'bytes' => strlen($draft)],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->cacheConfigContent = $draft;
            $this->cacheConfigEditing = false;
            $this->cacheConfigDraft = '';
            $this->toastSuccess(__('Config saved and :engine restarted.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Trigger a CLIENT LIST refresh. SSH happens in {@see \App\Jobs\RefreshCacheClientsJob}
     * so the Livewire commit returns immediately — no risk of PHP's 30s
     * max_execution_time biting a slow SSH link. The result lands in cache and
     * {@see pollCacheClients()} (the wire:poll tick) hydrates the component
     * from there.
     */
    public function loadCacheClients(): void
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
        \App\Jobs\RefreshCacheClientsJob::dispatch($row->id);
        $this->pollCacheClients();
    }

    public function hideCacheClients(): void
    {
        $this->cacheClients = null;
        $this->cacheClientsError = null;
        $this->cacheClientsPage = 1;
    }

    /**
     * Read the latest CLIENT LIST result that {@see \App\Jobs\RefreshCacheClientsJob}
     * wrote to cache and apply it to the component. Called both inline by
     * loadCacheClients (so the first paint shows last-known-good immediately
     * after a dispatch) and by wire:poll every 10s for live refresh.
     *
     * No SSH here — read-only against the cache.
     */
    public function pollCacheClients(): void
    {
        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            return;
        }

        // Re-dispatch a refresh on each poll tick so the cached result stays
        // current. Dispatching is non-blocking; the SSH work runs on a queue
        // worker. The next poll picks up the new value (or the same value if
        // the job hasn't finished yet — the UI stays on last-known-good).
        \App\Jobs\RefreshCacheClientsJob::dispatch($row->id);

        $payload = Cache::get(\App\Jobs\RefreshCacheClientsJob::resultCacheKey($row->server->id, $row->engine));
        if (! is_array($payload)) {
            return;
        }

        if (($payload['ok'] ?? false) === true) {
            $this->cacheClients = array_values(array_filter((array) ($payload['clients'] ?? []), 'is_array'));
            $this->cacheClientsError = null;

            $pageCount = max(1, (int) ceil(count($this->cacheClients) / self::CACHE_CLIENTS_PAGE_SIZE));
            $this->cacheClientsPage = max(1, min($this->cacheClientsPage, $pageCount));

            // Clear the "cached snapshot" banner once a write newer than the
            // hydrated `at` lands — that means the worker completed this
            // session and the data is fresh, not last-known-good.
            $newAt = isset($payload['at']) ? (string) $payload['at'] : '';
            if ($newAt !== '' && $newAt !== $this->cacheClientsCachedAt) {
                $this->cacheClientsFromCache = false;
                $this->cacheClientsCachedAt = $newAt;
            }
        } else {
            $this->cacheClientsError = (string) ($payload['error'] ?? __('Could not load clients.'));
        }
    }

    /**
     * Pull the engine's top-32 slowlog entries (SLOWLOG GET 32). Cached server-side
     * for {@see CacheServiceSlowlog} TTL so a wire:poll cycle doesn't hammer SSH.
     * Empty result + null error = engine is healthy; no commands have crossed the
     * `slowlog-log-slower-than` threshold (10ms default) in the ring buffer.
     */
    /**
     * Trigger a SLOWLOG refresh. SSH happens in {@see \App\Jobs\RefreshSlowlogJob}
     * — Livewire never blocks on it, so the 30s PHP timeout is impossible by
     * construction. Each tick re-dispatches; the next poll reads whatever the
     * worker has finished.
     */
    public function loadSlowlog(): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->slowlogError = __('Switch to an engine tab to view its slowlog.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->slowlogError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->slowlogError = __(':engine has no slowlog equivalent.', ['engine' => $row->engine]);

            return;
        }

        $this->slowlogError = null;
        \App\Jobs\RefreshSlowlogJob::dispatch($row->id);

        $payload = Cache::get(\App\Jobs\RefreshSlowlogJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($payload)) {
            if (($payload['ok'] ?? false) === true) {
                $this->slowlogEntries = array_values(array_filter((array) ($payload['entries'] ?? []), 'is_array'));

                $newAt = isset($payload['at']) ? (string) $payload['at'] : '';
                if ($newAt !== '' && $newAt !== $this->slowlogCachedAt) {
                    $this->slowlogFromCache = false;
                    $this->slowlogCachedAt = $newAt;
                }
            } else {
                $this->slowlogError = (string) ($payload['error'] ?? __('Could not load slowlog.'));
            }
        }
    }

    /**
     * Clear the engine's slowlog ring buffer. Audited via EVENT_SLOWLOG_RESET so an
     * operator's "clean state, start observing fresh" intent is recoverable from
     * the audit log if a perf investigation follows.
     */
    public function resetSlowlog(\App\Support\Servers\CacheServiceSlowlog $slowlog, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to reset its slowlog.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__(':engine has no slowlog equivalent.', ['engine' => $row->engine]));

            return;
        }

        if (! $slowlog->reset($row->server, $row)) {
            $this->toastError(__('Slowlog reset failed. Engine may be unreachable.'));

            return;
        }

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_SLOWLOG_RESET,
            ['engine' => $row->engine, 'name' => $row->name],
            auth()->user(),
        );

        $this->slowlogEntries = [];
        $this->slowlogError = null;
        $this->toastSuccess(__('Slowlog cleared on :engine.', ['engine' => $row->engine]));
    }

    /**
     * Lazy-load the persistence card data (RDB schedule, AOF on/off, last save).
     * Called via wire:init from the persistence card template.
     */
    public function loadPersistenceState(CacheServicePersistence $persistence): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->persistenceError = __('Switch to an engine tab to view its persistence state.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->persistenceError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->persistenceError = __(':engine has no persistence model.', ['engine' => $row->engine]);

            return;
        }

        $state = $persistence->state($row->server, $row);
        $this->persistenceState = $state;
        $this->persistenceError = null;
        if ($state['raw_save'] !== null && $this->rdb_save_schedule === '') {
            $this->rdb_save_schedule = $state['raw_save'];
        }
    }

    public function triggerBgsave(CacheServicePersistence $persistence, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $row || ! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Switch to a redis-family engine tab to trigger BGSAVE.'));

            return;
        }

        if (! $persistence->bgsave($row->server, $row)) {
            $this->toastError(__('BGSAVE failed. Engine may be unreachable.'));

            return;
        }

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_BGSAVE,
            ['engine' => $row->engine, 'name' => $row->name],
            auth()->user(),
        );

        $this->persistenceState = null;
        $this->toastSuccess(__('BGSAVE queued on :engine.', ['engine' => $row->engine]));
    }

    public function triggerBgrewriteaof(CacheServicePersistence $persistence, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $row || ! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Switch to a redis-family engine tab to trigger BGREWRITEAOF.'));

            return;
        }

        if (! $persistence->bgrewriteaof($row->server, $row)) {
            $this->toastError(__('BGREWRITEAOF failed. Engine may be unreachable.'));

            return;
        }

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_BGREWRITEAOF,
            ['engine' => $row->engine, 'name' => $row->name],
            auth()->user(),
        );

        $this->persistenceState = null;
        $this->toastSuccess(__('BGREWRITEAOF queued on :engine.', ['engine' => $row->engine]));
    }

    public function toggleAofPersistence(CacheServicePersistence $persistence, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $row || ! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Switch to a redis-family engine tab to toggle AOF.'));

            return;
        }

        $current = (bool) ($this->persistenceState['aof_enabled'] ?? false);
        $next = ! $current;

        if (! $persistence->setAofEnabled($row->server, $row, $next)) {
            $this->toastError(__('AOF toggle failed. Check the engine logs.'));

            return;
        }

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_AOF_TOGGLED,
            ['engine' => $row->engine, 'name' => $row->name, 'enabled' => $next],
            auth()->user(),
        );

        $this->persistenceState = null;
        $this->toastSuccess($next
            ? __('AOF enabled on :engine.', ['engine' => $row->engine])
            : __('AOF disabled on :engine.', ['engine' => $row->engine])
        );
    }

    public function saveRdbSchedule(CacheServicePersistence $persistence, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $row || ! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Switch to a redis-family engine tab to edit the RDB schedule.'));

            return;
        }

        // Parse space-separated `secs changes` pairs from the textarea. Validate
        // each pair before sending — easier than apologising to a Redis that
        // refused the new config and restarted with the old one.
        $raw = trim($this->rdb_save_schedule);
        $tokens = $raw === '' ? [] : (preg_split('/\s+/', $raw) ?: []);
        if (count($tokens) % 2 !== 0) {
            $this->addError('rdb_save_schedule', __('Schedule must be space-separated <seconds> <changes> pairs.'));

            return;
        }
        $schedule = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i += 2) {
            $secs = (int) $tokens[$i];
            $changes = (int) $tokens[$i + 1];
            if ($secs <= 0 || $changes <= 0) {
                $this->addError('rdb_save_schedule', __('Each <seconds> and <changes> must be positive integers.'));

                return;
            }
            $schedule[] = ['seconds' => $secs, 'changes' => $changes];
        }

        if (! $persistence->setSaveSchedule($row->server, $row, $schedule)) {
            $this->toastError(__('RDB schedule save failed. Check the engine logs.'));

            return;
        }

        $audits->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_RDB_SCHEDULE_SAVED,
            ['engine' => $row->engine, 'name' => $row->name, 'schedule' => $schedule],
            auth()->user(),
        );

        $this->persistenceState = null;
        $this->toastSuccess(__('RDB schedule saved on :engine.', ['engine' => $row->engine]));
    }

    /**
     * Read-only INFO replication parse. Renders the Stats-subtab Replication card —
     * role (master/replica), connected replicas (master side), master link status
     * (replica side). Mutating actions (REPLICAOF, add-replica wizard) come in 4b.
     */
    /**
     * Trigger an INFO-replication refresh. SSH happens in
     * {@see \App\Jobs\RefreshReplicationStateJob} — Livewire reads the result
     * from cache so the request never blocks on SSH.
     */
    public function loadReplicationState(): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->replicationError = __('Switch to an engine tab to view its replication state.');

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->replicationError = __('No :engine installed.', ['engine' => $engine]);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->replicationError = __(':engine has no replication.', ['engine' => $row->engine]);

            return;
        }

        $this->replicationError = null;
        \App\Jobs\RefreshReplicationStateJob::dispatch($row->id);

        $payload = Cache::get(\App\Jobs\RefreshReplicationStateJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($payload)) {
            if (($payload['ok'] ?? false) === true) {
                $this->replicationState = is_array($payload['state'] ?? null) ? $payload['state'] : null;

                $newAt = isset($payload['at']) ? (string) $payload['at'] : '';
                if ($newAt !== '' && $newAt !== $this->replicationCachedAt) {
                    $this->replicationFromCache = false;
                    $this->replicationCachedAt = $newAt;
                }
            } else {
                $this->replicationError = (string) ($payload['error'] ?? __('Could not load replication state.'));
            }
        }
    }

    /**
     * Submit the Add-Replica modal: validate the target, then attach via the
     * orchestrator (network exposure → REPLICAOF → poll for master_link_status=up).
     * On any step failure the orchestrator rolls back the replica config.
     */
    public function submitAddReplica(
        CacheServiceReplicaSetup $setup,
        CacheServiceAuditLogger $audits,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        $masterRow = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $masterRow || ! ServerCacheService::engineSupportsAuth($masterRow->engine)) {
            $this->toastError(__('Switch to a redis-family engine tab on the master to add a replica.'));

            return;
        }

        if ($this->addReplicaTargetServerId === '') {
            $this->addError('addReplicaTargetServerId', __('Pick a target server.'));

            return;
        }

        // Resolve the target server within the org. The picker scopes to
        // redis/valkey role hosts so a stray app server can't be selected.
        $targetServer = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($this->addReplicaTargetServerId)
            ->first();
        if (! $targetServer) {
            $this->addError('addReplicaTargetServerId', __('Target server not found in your organization.'));

            return;
        }
        if ($targetServer->id === $this->server->id) {
            $this->addError('addReplicaTargetServerId', __('Cannot use the master as its own replica.'));

            return;
        }

        // Find the matching engine row on the target.
        $replicaRow = ServerCacheService::query()
            ->where('server_id', $targetServer->id)
            ->where('engine', $masterRow->engine)
            ->first();
        if (! $replicaRow) {
            $this->addError('addReplicaTargetServerId', __('Target server has no :engine instance installed.', ['engine' => $masterRow->engine]));

            return;
        }

        if (ServerCacheServiceReplication::query()->where('replica_cache_service_id', $replicaRow->id)->exists()) {
            $this->addError('addReplicaTargetServerId', __('Target is already replicating from another master.'));

            return;
        }

        // DBSIZE pre-check: refuse to wipe a non-empty target unless the
        // operator ticked the acknowledgement checkbox. REPLICAOF flushes
        // the target — operators have lost data this way before.
        $dbsize = $this->checkReplicaDbSize($targetServer, $replicaRow);
        if ($dbsize > 0 && ! $this->addReplicaWipeAcknowledged) {
            $this->addError('addReplicaWipeAcknowledged', __('Target has :n keys. Replication WILL wipe them on attach. Tick the box to acknowledge.', ['n' => number_format($dbsize)]));

            return;
        }

        try {
            $row = $setup->attach($this->server, $masterRow, $targetServer, $replicaRow, (string) auth()->id());

            $audits->record(
                $this->server,
                ServerCacheServiceAuditEvent::EVENT_REPLICA_ATTACHED,
                [
                    'master_cache_service_id' => $masterRow->id,
                    'replica_cache_service_id' => $replicaRow->id,
                    'replica_server_id' => $targetServer->id,
                ],
                auth()->user(),
            );

            $this->addReplicaTargetServerId = '';
            $this->addReplicaWipeAcknowledged = false;
            $this->replicationState = null;
            $this->dispatch('close-modal', 'add-replica-modal');
            $this->toastSuccess(__('Replica attached on :host.', ['host' => $targetServer->name]));
        } catch (\Throwable $e) {
            $audits->record(
                $this->server,
                ServerCacheServiceAuditEvent::EVENT_REPLICA_ATTACH_FAILED,
                [
                    'master_cache_service_id' => $masterRow->id,
                    'replica_cache_service_id' => $replicaRow->id,
                    'error' => $e->getMessage(),
                ],
                auth()->user(),
            );
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Detach a replica from this master. Caller invokes via the Replication
     * card row's "Detach" button with the replication row id.
     */
    public function removeReplica(
        string $replicationRowId,
        CacheServiceReplicaSetup $setup,
        CacheServiceAuditLogger $audits,
    ): void {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        $masterRow = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $masterRow) {
            return;
        }

        $row = ServerCacheServiceReplication::query()
            ->where('master_cache_service_id', $masterRow->id)
            ->whereKey($replicationRowId)
            ->first();
        if (! $row) {
            return;
        }

        $replica = $row->replicaCacheService;
        $replicaServer = $replica?->server;
        if (! $replica || ! $replicaServer) {
            $row->delete();

            return;
        }

        try {
            $setup->detach($replicaServer, $replica, $row);
            $audits->record(
                $this->server,
                ServerCacheServiceAuditEvent::EVENT_REPLICA_DETACHED,
                [
                    'master_cache_service_id' => $masterRow->id,
                    'replica_cache_service_id' => $replica->id,
                ],
                auth()->user(),
            );

            $this->replicationState = null;
            $this->toastSuccess(__('Replica detached.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Seed {@see $cache_prefix_input} with the row's current cache_prefix so the
     * form shows the saved value on first render. Called via wire:init from the
     * Connection Details card; no-op when the row is missing or the input has
     * already been touched.
     */
    public function primeCachePrefix(): void
    {
        if ($this->cache_prefix_input !== '') {
            return;
        }
        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if ($row && filled($row->cache_prefix)) {
            $this->cache_prefix_input = (string) $row->cache_prefix;
        }
    }

    /**
     * Persist a Laravel-style cache key prefix on the current engine row. Surfaced
     * via the Connection Details card on the Overview subtab; reflected in the
     * Laravel `.env` and Docker Compose connection snippets as `CACHE_PREFIX=...`.
     * No remote action — this is a label dply stores so the snippet operators
     * paste matches the prefix they intend to use.
     */
    public function setCachePrefix(CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        $engine = $this->currentEngineTab();
        $row = $engine ? $this->cacheServiceFor($engine) : null;
        if (! $row) {
            $this->toastError(__('Switch to an engine tab to set its cache prefix.'));

            return;
        }

        $this->validate([
            'cache_prefix_input' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-:]*$/'],
        ], [], [
            'cache_prefix_input' => __('cache prefix'),
        ]);

        $normalised = trim($this->cache_prefix_input);
        $row->update(['cache_prefix' => $normalised === '' ? null : $normalised]);

        $audits->record(
            $row->server,
            \App\Models\ServerCacheServiceAuditEvent::EVENT_CACHE_PREFIX_UPDATED,
            ['engine' => $row->engine, 'name' => $row->name, 'value' => $normalised],
            auth()->user(),
        );

        $this->toastSuccess($normalised === ''
            ? __('Cache prefix cleared.')
            : __('Cache prefix set to :v', ['v' => $normalised])
        );
    }

    private function checkReplicaDbSize(Server $server, ServerCacheService $row): int
    {
        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        try {
            $output = app(ExecuteRemoteTaskOnServer::class)->runInlineBash(
                $server,
                'cache-service:replica-dbsize:'.$row->engine,
                $authFlag.escapeshellarg($cli).' -p '.(int) $row->port.' DBSIZE 2>/dev/null',
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable) {
            return 0;
        }

        return $output->exitCode === 0 ? (int) trim($output->buffer) : 0;
    }

    /**
     * Set the CLIENT LIST table page. Bounded to [1, pageCount] so a malformed
     * payload from a stale URL or back-button race can't strand the operator
     * on an empty slice.
     */
    public function setCacheClientsPage(int $page): void
    {
        if (! is_array($this->cacheClients) || $this->cacheClients === []) {
            $this->cacheClientsPage = 1;

            return;
        }

        $pageCount = (int) ceil(count($this->cacheClients) / self::CACHE_CLIENTS_PAGE_SIZE);
        $this->cacheClientsPage = max(1, min($page, max(1, $pageCount)));
    }

    /**
     * Trigger a refresh of the maxmemory + maxmemory-policy values from the
     * engine. SSH happens in {@see \App\Jobs\RefreshCacheMemorySettingsJob} —
     * the Livewire commit returns immediately and reads whatever the worker
     * has written to cache. PHP's 30s ceiling is never in play.
     */
    public function loadCacheMemorySettings(): void
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

        \App\Jobs\RefreshCacheMemorySettingsJob::dispatch($row->id);

        $payload = Cache::get(\App\Jobs\RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($payload) && ($payload['ok'] ?? false) === true) {
            $this->cache_maxmemory = (string) ($payload['maxmemory'] ?? '');
            $this->cache_maxmemory_policy = (string) ($payload['maxmemory_policy'] ?? 'noeviction');
            $this->cacheMemoryLoaded = true;
            $this->cacheMemoryError = null;

            $newAt = isset($payload['at']) ? (string) $payload['at'] : '';
            if ($newAt !== '' && $newAt !== $this->cacheMemoryCachedAt) {
                $this->cacheMemoryFromCache = false;
                $this->cacheMemoryCachedAt = $newAt;
            }
        } elseif (is_array($payload) && ($payload['ok'] ?? true) === false) {
            $this->cacheMemoryError = (string) ($payload['error'] ?? __('Could not load memory settings.'));
        }
    }

    public function hideCacheMemorySettings(): void
    {
        $this->cacheMemoryLoaded = false;
        $this->cache_maxmemory = '';
        $this->cache_maxmemory_policy = 'noeviction';
        $this->cacheMemoryError = null;
    }

    /**
     * Read-only poll for the cached memory-settings result. Called by
     * wire:poll on the idle state so the UI can pick up the worker write
     * shortly after {@see loadCacheMemorySettings} dispatched the job —
     * without this the operator clicks Load, nothing visible changes, and
     * they have to click again to see the result. No SSH, no dispatch here.
     *
     * Steady-state no-op once values are loaded — pollers fire every 1.5s
     * but we only touch component state when the cached payload's `at`
     * differs from what we last applied, so re-renders stop after hydration.
     */
    public function pollCacheMemorySettings(): void
    {
        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row || ! $row->server) {
            return;
        }

        // Self-healing: if the memory has never loaded this session, dispatch
        // a refresh on every poll tick. The job writes to cache and the next
        // tick picks it up. This ensures the card eventually loads even if
        // the click handler never fired the dispatch for any reason.
        if (! $this->cacheMemoryLoaded && $this->cacheMemoryError === null
            && ServerCacheService::engineSupportsAuth($row->engine)
        ) {
            \App\Jobs\RefreshCacheMemorySettingsJob::dispatch($row->id);
        }

        $payload = Cache::get(\App\Jobs\RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
        if (! is_array($payload)) {
            return;
        }

        $newAt = isset($payload['at']) ? (string) $payload['at'] : '';
        if ($newAt !== '' && $newAt === $this->cacheMemoryCachedAt) {
            // Same payload we already applied — nothing to do.
            return;
        }

        if (($payload['ok'] ?? false) === true) {
            $this->cache_maxmemory = (string) ($payload['maxmemory'] ?? '');
            $this->cache_maxmemory_policy = (string) ($payload['maxmemory_policy'] ?? 'noeviction');
            $this->cacheMemoryLoaded = true;
            $this->cacheMemoryError = null;
            $this->cacheMemoryFromCache = false;
            $this->cacheMemoryCachedAt = $newAt;
        } elseif (($payload['ok'] ?? true) === false) {
            $this->cacheMemoryError = (string) ($payload['error'] ?? __('Could not load memory settings.'));
            $this->cacheMemoryCachedAt = $newAt;
        }
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
            $maxNorm = $maxmemory === '' ? null : strtolower($maxmemory);
            $policyNorm = $policy === '' ? null : strtolower($policy);
            $this->runConsoleAction(
                $row,
                'cache_save_memory',
                __('Apply memory settings to :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($memory, $row, $maxNorm, $policyNorm, $audit, $maxmemory, $policy): void {
                    $emit->step('cache', sprintf('maxmemory=%s policy=%s', $maxNorm ?? 'unset', $policyNorm ?? 'unset'));
                    $memory->write($row->server, $row, $maxNorm, $policyNorm);
                    $emit->success('cache', 'Memory directives applied.');

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
                },
            );
            $this->toastSuccess(__('Memory settings applied to :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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
            $newAuth = $this->new_auth_password;
            $this->runConsoleAction(
                $row,
                'cache_set_auth',
                __('Set AUTH password on :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($auth, $row, $newAuth, $audits): void {
                    $emit->step('cache', sprintf('Setting requirepass on %s', $row->engine));
                    $auth->setRequirePass($row->server, $row, $newAuth);
                    $emit->success('cache', 'AUTH password active.');

                    $row->update(['auth_password' => $newAuth]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
                        ['engine' => $row->engine, 'name' => $row->name],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->new_auth_password = '';
            $this->toastSuccess(__('AUTH password set on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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
            $this->runConsoleAction(
                $row,
                'cache_clear_auth',
                __('Clear AUTH password on :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($auth, $row, $audits): void {
                    $emit->step('cache', sprintf('Clearing requirepass on %s', $row->engine));
                    $auth->clearRequirePass($row->server, $row);
                    $emit->success('cache', 'AUTH password cleared.');

                    $row->update(['auth_password' => null]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_AUTH_CLEARED,
                        ['engine' => $row->engine, 'name' => $row->name],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->toastSuccess(__('Cleared AUTH password on :engine.', ['engine' => $row->engine, 'name' => $row->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Change the listen port for the active instance on the current engine tab. Validates the
     * port range, rejects collisions with other cache services on this server (the
     * `unique(server_id, port)` constraint would otherwise blow up at the DB layer with an
     * ugly error), and delegates the on-server work to {@see CacheServicePort} which handles
     * config rewrite + restart + verify + revert. The DB row is updated only after the SSH
     * verify succeeds, so a failed port change leaves the row pointing at the old port.
     */
    public function changeCachePort(CacheServicePort $portChanger, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to change its port.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        $this->validate([
            'new_port' => ['required', 'integer', 'min:1024', 'max:65535'],
        ], [], [
            'new_port' => __('Port'),
        ]);

        $newPort = (int) $this->new_port;

        if ($newPort === $row->port) {
            $this->toastError(__('That is already the current port.'));

            return;
        }

        $collision = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('port', $newPort)
            ->where('id', '!=', $row->id)
            ->first();
        if ($collision !== null) {
            $this->toastError(__('Port :port is already used by :other on this server.', [
                'port' => $newPort,
                'other' => $collision->engine.' '.$collision->name,
            ]));

            return;
        }

        $oldPort = $row->port;

        try {
            $this->runConsoleAction(
                $row,
                'cache_change_port',
                __('Change :engine port :old → :new on :host', [
                    'engine' => $row->engine, 'old' => $oldPort, 'new' => $newPort, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($portChanger, $row, $newPort, $oldPort, $audits): void {
                    $emit->step('cache', sprintf('Rewriting %s config to listen on :%d', $row->engine, $newPort));
                    $portChanger->changePort($row->server, $row, $newPort);
                    $emit->success('cache', sprintf('%s now listening on :%d', $row->engine, $newPort));

                    $row->update(['port' => $newPort]);
                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
                        ['engine' => $row->engine, 'name' => $row->name, 'old_port' => $oldPort, 'new_port' => $newPort],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->new_port = null;
            $this->toastSuccess(__(':engine moved to port :port.', ['engine' => $row->engine, 'port' => $newPort]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Expose this cache instance to other servers in a network: rewrites the engine's bind to
     * 0.0.0.0, creates a panel firewall rule scoped to the source CIDR, and dispatches the
     * firewall apply. Refuses to expose Redis-family without an AUTH password — exposing an
     * un-authenticated cache to a network is the kind of foot-gun this dialog should prevent
     * even if the source CIDR is restrictive.
     */
    public function exposeCacheToNetwork(CacheServiceNetworkExposure $exposure, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->toastError(__('Switch to an engine tab to expose its instance.'));

            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->toastError(__('No :engine installed.', ['engine' => $engine]));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Network exposure is currently only supported for Redis-family engines (Redis, Valkey, KeyDB).'));

            return;
        }

        if (empty($row->auth_password)) {
            $this->toastError(__('Set an AUTH password first — exposing an un-authenticated cache to the network is too risky to allow from this dialog.'));

            return;
        }

        $this->validate([
            'expose_source_cidr' => ['required', 'string', 'max:64'],
        ], [], [
            'expose_source_cidr' => __('Source CIDR'),
        ]);

        try {
            $cidr = $this->expose_source_cidr;
            $this->runConsoleAction(
                $row,
                'cache_expose',
                __('Expose :engine on :host to :cidr', [
                    'engine' => $row->engine, 'host' => $this->server->name, 'cidr' => $cidr,
                ]),
                function (ConsoleEmitter $emit) use ($exposure, $row, $cidr, $audits): void {
                    $emit->step('cache', sprintf('Rewriting bind to 0.0.0.0; firewall rule for %s', $cidr));
                    $exposure->expose($row->server, $row, $cidr, auth()->id());
                    $emit->success('cache', 'Exposed; firewall apply queued.');

                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
                        ['engine' => $row->engine, 'name' => $row->name, 'change' => 'exposed', 'source' => $cidr],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->expose_source_cidr = '';
            $this->toastSuccess(__(':engine exposed on :port from :cidr — firewall apply queued.', [
                'engine' => $row->engine,
                'port' => $row->port,
                'cidr' => $cidr,
            ]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Reverse {@see exposeCacheToNetwork()} — bind back to 127.0.0.1, remove the firewall
     * rule, dispatch apply.
     */
    public function lockdownCacheToLoopback(CacheServiceNetworkExposure $exposure, CacheServiceAuditLogger $audits): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $engine = $this->currentEngineTab();
        if ($engine === null) {
            return;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            return;
        }

        try {
            $this->runConsoleAction(
                $row,
                'cache_lockdown',
                __('Lock :engine on :host to loopback', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($exposure, $row, $audits): void {
                    $emit->step('cache', 'Rewriting bind to 127.0.0.1; removing firewall rule');
                    $exposure->lockdown($row->server, $row, auth()->id());
                    $emit->success('cache', 'Locked down; firewall apply queued.');

                    $audits->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
                        ['engine' => $row->engine, 'name' => $row->name, 'change' => 'locked_down'],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
            $this->toastSuccess(__(':engine locked down to loopback — firewall apply queued.', ['engine' => $row->engine]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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
    /**
     * Trigger a fresh INFO sample. SSH + sampler delta math happen in
     * {@see \App\Jobs\RefreshKeyspaceSampleJob}; this method only dispatches
     * and reads the cached result. PHP's 30s timeout never applies because we
     * don't wait on SSH inside the Livewire commit.
     *
     * The previous sample (used by the sampler to compute ops/sec + hit-rate
     * window deltas) is passed into the job so the worker has everything it
     * needs without re-querying component state.
     */
    public function loadKeyspaceDashboard(): void
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

        $previous = end($this->keyspaceSamples) ?: null;
        \App\Jobs\RefreshKeyspaceSampleJob::dispatch($row->id, $previous ?: null);

        $this->ingestKeyspaceSampleFromCache($row->server->id, $row->engine);
    }

    public function pollKeyspaceDashboard(): void
    {
        // Polling tick: silently re-dispatch + ingest. wire:poll fires this
        // every 10s while the dashboard is open; transient failures stay quiet
        // so the operator keeps seeing the last-known-good buffer.
        try {
            $this->loadKeyspaceDashboard();
        } catch (\Throwable) {
            // swallow
        }
    }

    /**
     * Hydrate {@see $keyspaceSamples} from the cached result that
     * {@see \App\Jobs\RefreshKeyspaceSampleJob} writes. Appends the sample
     * (capped at KEYSPACE_SAMPLE_LIMIT) only when the job's `at` timestamp is
     * newer than the latest buffered sample — so back-to-back polls before a
     * worker has finished don't double-append the same sample.
     */
    protected function ingestKeyspaceSampleFromCache(string $serverId, string $engine): void
    {
        $payload = Cache::get(\App\Jobs\RefreshKeyspaceSampleJob::resultCacheKey($serverId, $engine));
        if (! is_array($payload)) {
            return;
        }

        if (($payload['ok'] ?? false) !== true) {
            $this->keyspaceError = (string) ($payload['error'] ?? __('Could not read INFO.'));

            return;
        }

        $sample = is_array($payload['sample'] ?? null) ? $payload['sample'] : null;
        if (! $sample) {
            return;
        }

        $latest = end($this->keyspaceSamples) ?: null;
        if (is_array($latest) && isset($latest['ts'], $sample['ts']) && (int) $sample['ts'] <= (int) $latest['ts']) {
            // Same or older sample — worker hasn't produced a new one yet.
            return;
        }

        $this->keyspaceSamples[] = $sample;
        if (count($this->keyspaceSamples) > self::KEYSPACE_SAMPLE_LIMIT) {
            $this->keyspaceSamples = array_slice(
                $this->keyspaceSamples,
                count($this->keyspaceSamples) - self::KEYSPACE_SAMPLE_LIMIT,
            );
        }

        $this->keyspaceLoaded = true;
        $this->keyspaceError = null;
        // Fresh sample landed this session — drop the "cached snapshot" banner.
        $this->keyspaceFromCache = false;

        $this->persistKeyspaceSamplesToCache();
    }

    public function hideKeyspaceDashboard(): void
    {
        $this->keyspaceSamples = [];
        $this->keyspaceLoaded = false;
        $this->keyspaceError = null;
    }

    /**
     * Run a fresh SCAN on the active instance: starts at cursor 0, drops any
     * previously-loaded keys + selected-key inspection, fetches the first page.
     * Operators call this from the "Search" / pattern-change UI.
     */
    public function searchKeyBrowser(CacheServiceKeyExplorer $explorer): void
    {
        $this->authorize('update', $this->server);

        $row = $this->resolveKeyBrowserRow();
        if (! $row) {
            return;
        }

        $this->keyBrowserKeys = [];
        $this->keyBrowserCursor = '0';
        $this->keyBrowserComplete = false;
        $this->keyBrowserSelected = null;
        $this->keyBrowserValue = null;
        $this->keyBrowserValueError = null;
        $this->keyBrowserError = null;
        $this->keyBrowserFromCache = false;
        $this->keysTablePage = 1;

        $this->loadKeyBrowserPage($explorer);
    }

    /**
     * Set the current page of the in-memory keys table. Bounded to
     * [1, pageCount] so a stale URL or back-button can't strand the
     * operator on an empty slice.
     */
    public function setKeysTablePage(int $page): void
    {
        $count = count($this->keyBrowserKeys);
        if ($count === 0) {
            $this->keysTablePage = 1;

            return;
        }

        $pageCount = max(1, (int) ceil($count / self::KEYS_TABLE_PAGE_SIZE));
        $this->keysTablePage = max(1, min($page, $pageCount));
    }

    /**
     * Fetch one page of keys via SCAN, append them to the existing list, and
     * advance the cursor. Idempotent against a completed scan (returns early).
     */
    public function loadKeyBrowserPage(CacheServiceKeyExplorer $explorer): void
    {
        $this->authorize('update', $this->server);

        $row = $this->resolveKeyBrowserRow();
        if (! $row) {
            return;
        }

        if ($this->keyBrowserComplete) {
            return;
        }

        try {
            $page = $explorer->scan($row->server, $row, $this->keyBrowserCursor, trim($this->keyBrowserPattern) ?: '*');
        } catch (\Throwable $e) {
            $this->keyBrowserError = $e->getMessage();

            return;
        }

        // Merge + dedupe: SCAN can repeat keys across iterations under heavy
        // write traffic. Operators only want one row per key.
        $this->keyBrowserKeys = array_values(array_unique(array_merge($this->keyBrowserKeys, $page['keys'])));
        $this->keyBrowserCursor = $page['cursor'];
        $this->keyBrowserComplete = $page['complete'];
        $this->keyBrowserLoaded = true;
        $this->keyBrowserError = null;
        // Fresh data — clear the "from cache" badge and persist for next visit.
        $this->keyBrowserFromCache = false;
        $this->persistKeyBrowserToSession();
    }

    /**
     * Inspect a specific key — TYPE + TTL + value (formatted by type, capped at
     * `CacheServiceKeyExplorer::MAX_VALUE_BYTES`). Sets the inspection panel's
     * state on the component.
     */
    public function inspectKey(string $key, CacheServiceKeyExplorer $explorer): void
    {
        $this->authorize('update', $this->server);

        $row = $this->resolveKeyBrowserRow();
        if (! $row) {
            return;
        }

        try {
            $result = $explorer->inspect($row->server, $row, $key);
        } catch (\Throwable $e) {
            $this->keyBrowserSelected = $key;
            $this->keyBrowserValue = null;
            $this->keyBrowserValueError = $e->getMessage();

            return;
        }

        $this->keyBrowserSelected = $key;
        $this->keyBrowserValue = $result;
        $this->keyBrowserValueError = null;
    }

    public function clearKeyInspection(): void
    {
        $this->keyBrowserSelected = null;
        $this->keyBrowserValue = null;
        $this->keyBrowserValueError = null;
    }

    public function hideKeyBrowser(): void
    {
        $this->keyBrowserKeys = [];
        $this->keyBrowserCursor = '0';
        $this->keyBrowserComplete = false;
        $this->keyBrowserLoaded = false;
        $this->keyBrowserSelected = null;
        $this->keyBrowserValue = null;
        $this->keyBrowserValueError = null;
        $this->keyBrowserError = null;
    }

    /**
     * Delete a key. Goes through the existing REPL unlock toggle as a safety
     * gate — DEL mutates state. Audited as a REPL_EXECUTED with verb=DEL so
     * the audit log captures the action consistently with the console.
     */
    public function deleteKey(string $key, CacheServiceCli $cli, CacheServiceCommandPolicy $policy, CacheServiceAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfCacheBusy()) {
            return;
        }

        $row = $this->resolveKeyBrowserRow();
        if (! $row) {
            return;
        }

        if (! $this->replUnlocked) {
            $this->toastError(__('Locked — flip the unlock toggle in the Console sub-tab to delete keys.'));

            return;
        }

        try {
            $output = $cli->execute($row->server, $row, 'DEL '.$key);
        } catch (\Throwable $e) {
            $this->keyBrowserValueError = $e->getMessage();

            return;
        }

        if ($output->exitCode !== 0) {
            $this->keyBrowserValueError = trim($output->buffer) ?: __('DEL command failed.');

            return;
        }

        // Pull the deleted key from the in-memory list and clear the inspection
        // panel if it was selected.
        $this->keyBrowserKeys = array_values(array_filter(
            $this->keyBrowserKeys,
            fn ($k) => $k !== $key,
        ));
        if ($this->keyBrowserSelected === $key) {
            $this->clearKeyInspection();
        }

        $audit->record(
            $row->server,
            ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
            ['engine' => $row->engine, 'name' => $row->name, 'verb' => 'DEL', 'mutating' => true, 'exit_code' => 0],
            auth()->user(),
        );
        $this->forgetStats($row);
        $this->toastSuccess(__('Deleted :key.', ['key' => $key]));
    }

    /**
     * Start a bounded MONITOR tail on the active instance. Generates a fresh
     * run ID (ULID-shaped string), dispatches the queued tail job, and the
     * polling Blade picks up the cache-buffer payload from there.
     *
     * MONITOR is read-only so we don't gate it on the REPL unlock toggle. The
     * window picker (5/10/30 s) plus the explainer already cover the CPU-cost
     * caveat for hot caches.
     */
    public function startMonitor(int $durationSeconds = 10): void
    {
        $this->authorize('update', $this->server);

        $row = $this->resolveKeyBrowserRow();
        if (! $row) {
            return;
        }

        if ($this->monitorRunId !== '') {
            $this->toastError(__('A MONITOR run is already in flight on this instance. Wait for it to finish.'));

            return;
        }

        $duration = max(
            TailCacheServiceMonitorJob::MIN_DURATION,
            min(TailCacheServiceMonitorJob::HARD_MAX_DURATION, $durationSeconds),
        );

        $this->monitorRunId = (string) Str::ulid();
        $this->monitorDurationSeconds = $duration;
        $this->monitorPayload = [
            'status' => 'queued',
            'lines' => [],
            'error' => null,
        ];

        TailCacheServiceMonitorJob::dispatch(
            $this->server->id,
            $row->id,
            $this->monitorRunId,
            $duration,
        );
    }

    /**
     * Poll the MONITOR cache buffer for the active run. Called via
     * `wire:poll.1s` while `monitorRunId` is set; clears the run id once the
     * job reports `completed` or `failed`.
     */
    public function pollMonitorOutput(): void
    {
        if ($this->monitorRunId === '') {
            return;
        }

        $payload = Cache::get(TailCacheServiceMonitorJob::cacheKey($this->monitorRunId));
        if (! is_array($payload)) {
            return;
        }

        $this->monitorPayload = [
            'status' => (string) ($payload['status'] ?? 'running'),
            'lines' => array_values((array) ($payload['lines'] ?? [])),
            'error' => $payload['error'] ?? null,
        ];

        if (in_array($this->monitorPayload['status'], ['completed', 'failed'], true)) {
            // Stop polling but keep the buffer visible so the operator can scroll
            // through the captured lines after the window ends.
            $this->monitorRunId = '';
        }
    }

    public function clearMonitorOutput(): void
    {
        $this->monitorRunId = '';
        $this->monitorPayload = null;
    }

    /**
     * Reverb chunk dispatched from `bootstrap.js` when a MONITOR broadcast
     * arrives on the `server.{serverId}` private channel. The JS layer is the
     * Reverb client; this method just appends the chunk to our in-memory
     * buffer for the active run.
     *
     * Drops events for runs the operator isn't watching (e.g. another
     * operator's run on the same server) by checking against `monitorRunId`.
     */
    #[On('cache-monitor-chunk')]
    public function onMonitorChunk(string $runId, string $chunk): void
    {
        if ($runId !== $this->monitorRunId || $this->monitorRunId === '') {
            return;
        }

        $payload = $this->monitorPayload ?? ['status' => 'running', 'lines' => [], 'error' => null];
        $payload['status'] = 'running';

        // Split the broadcast chunk on newlines and append each non-empty
        // line. Bound at 500 lines (oldest dropped) to mirror the job's
        // server-side buffer behavior.
        $lines = $payload['lines'];
        foreach (explode("\n", $chunk) as $line) {
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
        }
        if (count($lines) > 500) {
            $lines = array_slice($lines, -500);
        }

        $payload['lines'] = $lines;
        $this->monitorPayload = $payload;
    }

    #[On('cache-monitor-completed')]
    public function onMonitorCompleted(string $runId, bool $success, int $lineCount, ?string $error = null): void
    {
        if ($runId !== $this->monitorRunId || $this->monitorRunId === '') {
            return;
        }

        $payload = $this->monitorPayload ?? ['status' => 'running', 'lines' => [], 'error' => null];
        $payload['status'] = $success ? 'completed' : 'failed';
        $payload['error'] = $error;
        $this->monitorPayload = $payload;
        $this->monitorRunId = '';
    }

    /**
     * Resolve the row the key browser operates on (active instance of the
     * current engine tab). Returns null and sets a friendly error when the
     * engine doesn't support SCAN (memcached) or there's nothing installed.
     */
    protected function resolveKeyBrowserRow(): ?ServerCacheService
    {
        $engine = $this->currentEngineTab();
        if ($engine === null) {
            $this->keyBrowserError = __('Switch to an engine tab to browse its keys.');

            return null;
        }

        $row = $this->cacheServiceFor($engine);
        if (! $row) {
            $this->keyBrowserError = __('No :engine instance installed.', ['engine' => $engine]);

            return null;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->keyBrowserError = __(':engine has no SCAN equivalent — the key browser is redis-family only.', ['engine' => $row->engine]);

            return null;
        }

        return $row;
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
            $this->runConsoleAction(
                $row,
                'cache_flush',
                __('Flush all keys on :engine on :host', ['engine' => $row->engine, 'host' => $this->server->name]),
                function (ConsoleEmitter $emit) use ($executor, $row, $cmd, $audit): void {
                    $output = $executor->runInlineBash(
                        $row->server,
                        'cache-service:flush:'.$row->engine,
                        $cmd,
                        timeoutSeconds: 30,
                        asRoot: false,
                    );
                    $this->emitExecutorBuffer($emit, $output->buffer, $output->exitCode, 'flush');

                    $audit->record(
                        $row->server,
                        ServerCacheServiceAuditEvent::EVENT_FLUSHED,
                        ['engine' => $row->engine, 'name' => $row->name],
                        auth()->user(),
                    );
                    $this->forgetStats($row);
                },
            );
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
        ?string $label = null,
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

        // Per-instance systemd unit: `valkey-server` for the legacy `default`
        // instance, `valkey-server@<name>` for templated instances. Using
        // systemdServiceFor() alone was a bug — Stop on a named instance
        // would silently target the default unit and stop the wrong instance.
        $service = CacheServiceInstallScripts::instanceServiceUnit($row->engine, $row->name);
        $serviceShell = escapeshellarg($service);
        // Wrap the bare `systemctl <verb>` with a follow-up status print so the console panel
        // shows what actually happened. `systemctl <verb>` is silent on success and only stderrs
        // on failure; the trailing status (always run via `|| true`) gives the operator real-time
        // confirmation without a second click.
        $script = <<<BASH
echo "═══ systemctl {$verb} {$service} ═══"
systemctl {$verb} {$serviceShell}
verb_exit=\$?
echo
echo "═══ systemctl status (post-{$verb}) ═══"
systemctl status --no-pager --lines=15 {$serviceShell} 2>&1 || true
exit \$verb_exit
BASH;
        // Caller-provided label (e.g. "Disable" for `disable --now`) takes precedence so the
        // console banner header doesn't read "Disable --now". Fall back to titlecased verb for
        // the simple restart/stop/start cases.
        $label = $label ?? __(ucfirst($verb));
        // First word of the verb keyed into a stable kind slug so banner-getters can
        // filter to "cache_*" rows. `disable --now` collapses to `cache_disable`, etc.
        $kindVerb = strtolower((string) preg_replace('/\W.*/', '', $verb));
        try {
            $this->runConsoleAction(
                $row,
                'cache_'.$kindVerb,
                __(':label :engine on :host', [
                    'label' => $label, 'engine' => $row->engine, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($executor, $row, $verb, $script, $audit, $event, $newStatus): void {
                    $output = $executor->runInlineBash(
                        $row->server,
                        'cache-service:'.$verb.':'.$row->engine.':'.$row->name,
                        $script,
                        timeoutSeconds: 60,
                        asRoot: true,
                    );
                    // emitExecutorBuffer throws on non-zero exit so runConsoleAction's
                    // catch block flips the row to failed without us double-handling.
                    $this->emitExecutorBuffer($emit, $output->buffer, $output->exitCode, $verb);

                    if ($newStatus) {
                        $row->update(['status' => $newStatus]);
                    }
                    $audit->record($row->server, $event, ['engine' => $row->engine, 'name' => $row->name], auth()->user());
                    $this->forgetStats($row);
                },
            );
            $this->toastSuccess(__(':verb succeeded for :engine — see the console banner above.', [
                'verb' => ucfirst($verb),
                'engine' => $row->engine,
            ]));
        } catch (\Throwable) {
            $this->toastError(__(':verb failed for :engine — see the console banner above.', [
                'verb' => ucfirst($verb), 'engine' => $row->engine,
            ]));
        }
    }

    public function render(
        CacheServiceStats $statsService,
    ): View {
        // Engine capabilities + distro-support gates are SSH-probed off the render path via
        // wire:init (loadCacheCapabilities) so the workspace paints instantly; the per-engine
        // "running" badge and Install gate resolve once that returns. $capabilitiesLoaded gates
        // the "checking…" UI. Cached 24h, so this is usually a no-op after the first load.
        $capabilities = $this->capabilitiesLoaded
            ? ($this->capabilities_state ?: ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false])
            : ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];

        $engineUnsupportedReasons = $this->capabilitiesLoaded
            ? ($this->cache_unsupported_reasons ?: ['redis' => null, 'valkey' => null, 'memcached' => null, 'keydb' => null, 'dragonfly' => null])
            : ['redis' => null, 'valkey' => null, 'memcached' => null, 'keydb' => null, 'dragonfly' => null];

        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        if (! in_array($this->workspace_tab, $allowed, true)) {
            $this->workspace_tab = 'overview';
        }

        // Drop any memo a pre-render busy-check populated so this render reads
        // rows live (incl. ones a mutating action just created), then share that
        // single fetch with every downstream consumer in this lifecycle.
        $this->cacheServicesMemo = null;
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

        // One row per (server, engine) post-collapse — keyBy gives the per-engine row directly
        // so the view's foreach can grab `$cacheServicesByEngine->get($engine)` without the
        // legacy (engine, name) double-grouping the multi-instance era required.
        $primaryByEngine = $services->keyBy('engine');

        // Per-engine console-action runs. The blade renders the static banner whenever a
        // matching row exists; filtering by 'cache_' kind family keeps unrelated runs
        // (notification dispatches, audit replay) from leaking onto a cache banner.
        $cacheRunsByEngine = $primaryByEngine
            ->mapWithKeys(fn (ServerCacheService $row): array => [
                $row->engine => $this->latestConsoleActionFor($row, 'cache_'),
            ])
            ->filter()
            ->all();

        $auditEvents = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        // Latest non-dismissed manage_action run for this server — drives the
        // Show Redis INFO output banner on the redis Stats subtab.
        $manageActionRun = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        // Candidate replica servers for the Add-Replica modal: org-owned,
        // redis/valkey role, READY, and not yet replicating from another master.
        $availableReplicaServers = collect();
        $activeReplications = collect();
        if ($this->engine_subtab === 'stats') {
            $availableReplicaServers = Server::query()
                ->where('organization_id', $this->server->organization_id)
                ->where('id', '!=', $this->server->id)
                ->where('status', Server::STATUS_READY)
                ->where(function ($q): void {
                    $q->whereJsonContains('meta->server_role', 'redis')
                        ->orWhereJsonContains('meta->server_role', 'valkey');
                })
                ->orderBy('name')
                ->get();

            $masterEngine = $this->currentEngineTab();
            $masterRow = $masterEngine ? $this->cacheServiceFor($masterEngine) : null;
            if ($masterRow) {
                $activeReplications = ServerCacheServiceReplication::query()
                    ->where('master_cache_service_id', $masterRow->id)
                    ->with(['replicaCacheService.server'])
                    ->get();
            }
        }

        return view('livewire.servers.workspace-caches', array_merge(
            CacheWorkspaceViewData::for($this->server, $this, $services),
            [
                'capabilities' => $capabilities,
                'capabilitiesLoaded' => $this->capabilitiesLoaded,
                'engineUnsupportedReasons' => $engineUnsupportedReasons,
                'cacheServices' => $services,
                'cacheServicesByEngine' => $primaryByEngine,
                'cacheRunsByEngine' => $cacheRunsByEngine,
                'cacheStatsByInstance' => $statsByInstance,
                'cacheAuditEvents' => $auditEvents,
                // Allowlisted manage actions exposed on Caches (currently just `redis_info`).
                // Banner-only flow — see RunsAllowlistedManageAction.
                'serviceActions' => config('server_manage.service_actions', []),
                'manageActionRun' => $manageActionRun,
                'deletionSummary' => null,
                'availableReplicaServers' => $availableReplicaServers,
                'activeReplications' => $activeReplications,
            ],
        ));
    }

    /** All cache service rows for this server, keyed by ULID and ordered by engine name. */
    /**
     * Request-scoped memo for {@see cacheServices()}. A single render fans the
     * row set out to several consumers (the render() body, the
     * {@see cacheConsumers()} computed, {@see CacheWorkspaceViewData}), each of
     * which used to re-run the identical `server_cache_services` select.
     * render() nulls this before its first read, so the rendered view always
     * reflects rows a mutating action just created — the memo only collapses
     * reads within one lifecycle, it never carries a stale set into a render.
     */
    private ?Collection $cacheServicesMemo = null;

    protected function cacheServices(): Collection
    {
        return $this->cacheServicesMemo ??= ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->orderBy('engine')
            ->get();
    }

    /**
     * Sites consuming this server's cache services, grouped by cache-service id —
     * the "Used by" list on each engine's Overview. One query for the whole tab.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function cacheConsumers(): array
    {
        return $this->buildBindingConsumers(
            'server_cache_service',
            $this->cacheServices()->pluck('id')->all(),
            $this->server->id,
        );
    }

    /**
     * Look up the engine row on this server. With one-row-per-engine, this is the row every
     * per-engine action operates on. The `name` filter survives only to ignore any historical
     * non-default rows that the collapse migration somehow missed.
     */
    protected function cacheServiceFor(string $engine): ?ServerCacheService
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', ServerCacheService::DEFAULT_INSTANCE_NAME)
            ->first();
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
        // Reset the cache_prefix editor so the next engine tab re-seeds from
        // its own row via primeCachePrefix(). Without this, jumping from a
        // Redis instance with prefix "app_a_" to a Valkey instance with no
        // prefix would carry "app_a_" into the Valkey form by mistake.
        $this->cache_prefix_input = '';

        // REPL + dashboard + key browser are scoped to the current engine, so
        // their buffers go away on tab change. The unlock toggle deliberately
        // does NOT reset on tab change — it resets on remount, matching the
        // existing "session-scoped trust" pattern in this component.
        $this->replInput = '';
        $this->replHistory = [];
        $this->keyspaceSamples = [];
        $this->keyspaceLoaded = false;
        $this->keyspaceError = null;
        $this->keyBrowserPattern = '*';
        $this->keyBrowserCursor = '0';
        $this->keyBrowserKeys = [];
        $this->keyBrowserLoaded = false;
        $this->keyBrowserComplete = false;
        $this->keyBrowserSelected = null;
        $this->keyBrowserValue = null;
        $this->keyBrowserValueError = null;
        $this->keyBrowserError = null;
        $this->monitorRunId = '';
        $this->monitorPayload = null;
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
