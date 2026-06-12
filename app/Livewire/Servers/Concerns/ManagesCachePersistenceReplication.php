<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshReplicationStateJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\ServerCacheServiceReplication;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServicePersistence;
use App\Support\Servers\CacheServiceReplicaSetup;
use App\Support\Servers\CacheServiceStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCachePersistenceReplication
{
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
     * RDB save-schedule editor input. Space-separated `seconds changes` pairs —
     * e.g. "3600 1 300 100" snapshots every 3600s after 1 change or every 300s
     * after 100 changes. Empty disables RDB entirely. Populated from
     * persistence state on subtab mount.
     */
    public string $rdb_save_schedule = '';

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
     * {@see RefreshReplicationStateJob} — Livewire reads the result
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
        RefreshReplicationStateJob::dispatch($row->id);

        $payload = Cache::get(RefreshReplicationStateJob::resultCacheKey($row->server->id, $row->engine));
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
}
