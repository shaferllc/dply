<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshCacheMemorySettingsJob;
use App\Jobs\RefreshSlowlogJob;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceConfigWriter;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceMemoryConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheConfiguration
{
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

    /** True after `loadCacheMemorySettings` populates the form below. */
    public bool $cacheMemoryLoaded = false;

    public string $cache_maxmemory = '';

    public string $cache_maxmemory_policy = 'noeviction';

    public ?string $cacheMemoryError = null;

    /** See {@see $cacheClientsFromCache} — same pattern for the memory-settings card. */
    public bool $cacheMemoryFromCache = false;

    public ?string $cacheMemoryCachedAt = null;

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
     * Pull the engine's top-32 slowlog entries (SLOWLOG GET 32). Cached server-side
     * for {@see CacheServiceSlowlog} TTL so a wire:poll cycle doesn't hammer SSH.
     * Empty result + null error = engine is healthy; no commands have crossed the
     * `slowlog-log-slower-than` threshold (10ms default) in the ring buffer.
     */
    /**
     * Trigger a SLOWLOG refresh. SSH happens in {@see RefreshSlowlogJob}
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
        RefreshSlowlogJob::dispatch($row->id);

        $payload = Cache::get(RefreshSlowlogJob::resultCacheKey($row->server->id, $row->engine));
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
     * Trigger a refresh of the maxmemory + maxmemory-policy values from the
     * engine. SSH happens in {@see RefreshCacheMemorySettingsJob} —
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

        RefreshCacheMemorySettingsJob::dispatch($row->id);

        $payload = Cache::get(RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
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
            RefreshCacheMemorySettingsJob::dispatch($row->id);
        }

        $payload = Cache::get(RefreshCacheMemorySettingsJob::resultCacheKey($row->server->id, $row->engine));
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
}
