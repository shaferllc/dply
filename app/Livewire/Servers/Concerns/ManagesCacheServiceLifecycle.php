<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\RecheckCacheServiceJob;
use App\Jobs\RefreshCacheClientsJob;
use App\Jobs\RefreshCacheMemorySettingsJob;
use App\Jobs\RefreshReplicationStateJob;
use App\Jobs\RefreshSlowlogJob;
use App\Jobs\StatusCacheServiceJob;
use App\Jobs\UninstallCacheServiceJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheEngineAvailability;
use App\Support\Servers\CacheEngineInfo;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheServiceLifecycle
{
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
     * Active instance name within the current per-engine tab. Historically URL-bound so deep
     * links to a named instance worked; with multi-instance retired (one row per engine, name
     * always `'default'`) this stays as a const-shaped property so legacy reads
     * (`$row->name === $this->active_instance`) keep working without rewriting every call site.
     */
    public string $active_instance = ServerCacheService::DEFAULT_INSTANCE_NAME;

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

        $clients = Cache::get(RefreshCacheClientsJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($clients) && ($clients['ok'] ?? false) === true) {
            $this->cacheClients = array_values(array_filter((array) ($clients['clients'] ?? []), 'is_array'));
            $this->cacheClientsFromCache = true;
            $this->cacheClientsCachedAt = isset($clients['at']) ? (string) $clients['at'] : null;
        }

        $slowlog = Cache::get(RefreshSlowlogJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($slowlog) && ($slowlog['ok'] ?? false) === true) {
            $this->slowlogEntries = array_values(array_filter((array) ($slowlog['entries'] ?? []), 'is_array'));
            $this->slowlogFromCache = true;
            $this->slowlogCachedAt = isset($slowlog['at']) ? (string) $slowlog['at'] : null;
        }

        $replication = Cache::get(RefreshReplicationStateJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($replication) && ($replication['ok'] ?? false) === true) {
            $this->replicationState = is_array($replication['state'] ?? null) ? $replication['state'] : null;
            $this->replicationFromCache = true;
            $this->replicationCachedAt = isset($replication['at']) ? (string) $replication['at'] : null;
        }

        $memory = Cache::get(RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
        if (is_array($memory) && ($memory['ok'] ?? false) === true) {
            $this->cache_maxmemory = (string) ($memory['maxmemory'] ?? '');
            $this->cache_maxmemory_policy = (string) ($memory['maxmemory_policy'] ?? 'noeviction');
            $this->cacheMemoryLoaded = true;
            $this->cacheMemoryFromCache = true;
            $this->cacheMemoryCachedAt = isset($memory['at']) ? (string) $memory['at'] : null;
        }
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

        RecheckCacheServiceJob::dispatch($consoleActionId, $row->id);
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
        StatusCacheServiceJob::dispatch($consoleActionId, $row->id, $view);
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
}
