<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshKeyspaceSampleJob;
use App\Jobs\TailCacheServiceMonitorJob;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceCommandPolicy;
use App\Support\Servers\CacheServiceKeyExplorer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheKeyspace
{
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
     * Bounded ring buffer of dashboard samples. Capped at KEYSPACE_SAMPLE_LIMIT.
     *
     * @var list<array<string, mixed>>
     */
    public array $keyspaceSamples = [];

    public bool $keyspaceLoaded = false;

    public ?string $keyspaceError = null;

    /** See {@see $cacheClientsFromCache} — set when samples come from cache, cleared on first fresh sample. */
    public bool $keyspaceFromCache = false;

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
            ServerCacheServiceAuditEvent::EVENT_CACHE_PREFIX_UPDATED,
            ['engine' => $row->engine, 'name' => $row->name, 'value' => $normalised],
            auth()->user(),
        );

        $this->toastSuccess($normalised === ''
            ? __('Cache prefix cleared.')
            : __('Cache prefix set to :v', ['v' => $normalised])
        );
    }

    /**
     * Pull a fresh INFO sample, append to the dashboard ring buffer, and trim. The
     * sampler computes deltas relative to the previous sample for ops/sec and hit-rate
     * windows; absolute values for memory and clients come straight from the latest INFO.
     */
    /**
     * Trigger a fresh INFO sample. SSH + sampler delta math happen in
     * {@see RefreshKeyspaceSampleJob}; this method only dispatches
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
        RefreshKeyspaceSampleJob::dispatch($row->id, $previous ?: null);

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
     * {@see RefreshKeyspaceSampleJob} writes. Appends the sample
     * (capped at KEYSPACE_SAMPLE_LIMIT) only when the job's `at` timestamp is
     * newer than the latest buffered sample — so back-to-back polls before a
     * worker has finished don't double-append the same sample.
     */
    protected function ingestKeyspaceSampleFromCache(string $serverId, string $engine): void
    {
        $payload = Cache::get(RefreshKeyspaceSampleJob::resultCacheKey($serverId, $engine));
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
}
