<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Concerns\SurfacesBindingConsumers;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesCacheClients;
use App\Livewire\Servers\Concerns\ManagesCacheConfiguration;
use App\Livewire\Servers\Concerns\ManagesCacheKeyspace;
use App\Livewire\Servers\Concerns\ManagesCachePersistenceReplication;
use App\Livewire\Servers\Concerns\ManagesCacheSecurity;
use App\Livewire\Servers\Concerns\ManagesCacheServiceLifecycle;
use App\Livewire\Servers\Concerns\ManagesCacheView;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\CacheWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

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
    use ManagesCacheClients;
    use ManagesCacheConfiguration;
    use ManagesCacheKeyspace;
    use ManagesCachePersistenceReplication;
    use ManagesCacheSecurity;
    use ManagesCacheServiceLifecycle;
    use ManagesCacheView;
    use RunsAllowlistedManageAction;
    use RunsServerConsoleActions;
    use SurfacesBindingConsumers;

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
    public const ENGINE_SUBTABS = ['overview', 'info', 'console', 'stats', 'configure'];

    /**
     * Page size + current page for the CLIENT LIST table. Pagination is
     * client-side (we already have the full list in memory) so prev/next
     * doesn't re-SSH — the snapshot is cheap to slice. Reset to page 1 on
     * every fresh `loadCacheClients` so a refresh doesn't strand the operator
     * on a page that no longer exists.
     */
    public const CACHE_CLIENTS_PAGE_SIZE = 10;

    public const REPL_HISTORY_LIMIT = 50;

    public const KEYSPACE_SAMPLE_LIMIT = 60;

    /**
     * Client-side pagination of the in-memory key list. The SCAN buffer can
     * accumulate hundreds of keys across "Load more" presses; rendering them
     * all at once creates a scrolling wall — paginating in slices of 25 keeps
     * the result table readable and prev/next is free (just an array slice).
     */
    public const KEYS_TABLE_PAGE_SIZE = 25;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->hydrateKeyBrowserFromSession();
        $this->hydrateKeyspaceSamplesFromCache();
        $this->hydrateCacheStatsFromResultCache();
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

    /** @internal Drop the stats cache for a row's engine. */
    private function forgetStats(?ServerCacheService $row): void
    {
        if ($row === null) {
            return;
        }
        app(CacheServiceStats::class)->forget($row->server, $row->engine);
    }
}
