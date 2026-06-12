<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshCacheClientsJob;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceCommandPolicy;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheClients
{
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

    public int $cacheClientsPage = 1;

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

    /**
     * Trigger a CLIENT LIST refresh. SSH happens in {@see RefreshCacheClientsJob}
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
        RefreshCacheClientsJob::dispatch($row->id);
        $this->pollCacheClients();
    }

    public function hideCacheClients(): void
    {
        $this->cacheClients = null;
        $this->cacheClientsError = null;
        $this->cacheClientsPage = 1;
    }

    /**
     * Read the latest CLIENT LIST result that {@see RefreshCacheClientsJob}
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
        RefreshCacheClientsJob::dispatch($row->id);

        $payload = Cache::get(RefreshCacheClientsJob::resultCacheKey($row->server->id, $row->engine));
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
}
