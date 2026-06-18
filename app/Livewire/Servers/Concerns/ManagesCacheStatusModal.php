<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RecheckCacheServiceJob;
use App\Jobs\RefreshCacheClientsJob;
use App\Jobs\RefreshCacheMemorySettingsJob;
use App\Jobs\RefreshReplicationStateJob;
use App\Jobs\RefreshSlowlogJob;
use App\Jobs\StatusCacheServiceJob;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheStatusModal
{


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
}
