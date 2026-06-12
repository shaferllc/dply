<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\ToggleDatabaseEngineActivationJob;
use App\Jobs\ToggleDatabaseEngineRemoteAccessJob;
use App\Jobs\ToggleDatabaseNetworkingJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Models\ConsoleAction;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDriftAnalyzer;
use App\Support\Servers\DatabaseEngineAvailability;
use App\Support\Servers\DatabaseEngineInfo;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseEngineLifecycle
{
    /** @var list<string> */
    public array $remote_mysql_databases = [];

    /** @var list<string> */
    public array $remote_postgres_databases = [];

    /** @var list<string> */
    public array $remote_mongodb_databases = [];

    /** @var list<string> */
    public array $remote_clickhouse_databases = [];

    public string $remote_access_engine = '';

    public string $remote_access_allowed_from = '0.0.0.0/0';

    /** Keyed by database ID — the CIDR input value for each row's networking form. */
    public array $db_networking_allowed_from = [];

    /** @var array<string, mixed>|null */
    public ?array $drift_snapshot = null;

    /** True once loadDriftSnapshot() has run for the current Connections subtab view. */
    public bool $drift_loaded = false;

    /**
     * Engine capabilities are SSH-probed off the render path (wire:init →
     * loadCapabilities) so the workspace paints instantly. $capabilitiesLoaded
     * gates the "checking…" UI; $capabilities_state holds the resolved map.
     *
     * @var array<string, bool>
     */
    public array $capabilities_state = [];

    public bool $capabilitiesLoaded = false;

    /**
     * Queue an install for the requested database engine. Mirrors the Caches workspace install
     * flow: a `ServerDatabaseEngine` row is created in PENDING; the queued job runs preflight,
     * apt-installs, parses the version, and flips status to RUNNING.
     *
     * Workspace tabs are engine families ('mysql', 'postgres'); the engine column on the row
     * holds the canonical short key the install scripts understand.
     */
    public function installDatabaseEngine(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($engine, DatabaseEngineInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported database engine.'));

            return;
        }

        // Coming-soon gate — MariaDB / MongoDB / ClickHouse are gated behind
        // database.{engine} flags until their install path is GA. Refuse before
        // queueing so a stale payload can't slip past the disabled UI.
        if (DatabaseEngineAvailability::isComingSoon($engine)) {
            $this->toastError(__(':engine isn\'t available yet — it\'s coming soon.', [
                'engine' => DatabaseEngineInfo::for($engine)['label'] ?? ucfirst($engine),
            ]));

            return;
        }

        $existing = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if ($existing && $existing->status === ServerDatabaseEngine::STATUS_RUNNING) {
            $this->toastError(__(':engine is already installed.', ['engine' => $engine]));

            return;
        }

        $row = $existing ?? ServerDatabaseEngine::query()->create([
            'server_id' => $this->server->id,
            'engine' => $engine,
            'status' => ServerDatabaseEngine::STATUS_PENDING,
            'is_default' => ServerDatabaseEngine::query()->where('server_id', $this->server->id)->doesntExist(),
            'port' => ServerDatabaseEngine::defaultPortFor($engine),
        ]);

        // Re-run install only when the row is in a state that makes sense to retry from.
        if (! in_array($row->status, [
            ServerDatabaseEngine::STATUS_PENDING,
            ServerDatabaseEngine::STATUS_FAILED,
            ServerDatabaseEngine::STATUS_STOPPED,
        ], true)) {
            $this->toastError(__('Install is already in progress.'));

            return;
        }

        $this->workspace_tab = $engine;

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_install',
            __('Installing :engine on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        InstallDatabaseEngineJob::dispatch($row->id, auth()->id());
        $this->toastSuccess(__('Installing :engine — progress shows in the banner above.', ['engine' => $engineLabel]));
    }

    /**
     * Operator escape hatch when a database-engine install has stalled. Mirrors
     * the webserver-switch and cache-install Stop & revert patterns: marks the
     * pending/installing row FAILED with an "operator-aborted" reason, then
     * dispatches {@see UninstallDatabaseEngineJob} to apt-purge whatever the
     * install partially landed (idempotent — the uninstall script tolerates
     * "not actually installed" too).
     *
     * The install job's success-path update (`status = running`) is keyed by
     * row id; once it finishes its long SSH bash it'll find the row in FAILED
     * state and the uninstall will already be queued behind it on the
     * server_database.install_queue, so the two serialise rather than race.
     */
    public function stopAndRevertDatabaseEngineInstall(string $engine): void
    {
        $this->authorize('update', $this->server);

        $engine = strtolower(trim($engine));
        if (! in_array($engine, DatabaseEngineInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported database engine.'));

            return;
        }

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if ($row === null) {
            $this->toastError(__('No :engine install to stop.', ['engine' => $engine]));

            return;
        }

        if (! in_array($row->status, [
            ServerDatabaseEngine::STATUS_PENDING,
            ServerDatabaseEngine::STATUS_INSTALLING,
        ], true)) {
            $this->toastError(__(':engine is not currently installing (status: :status).', [
                'engine' => $engine,
                'status' => $row->status,
            ]));

            return;
        }

        $row->update([
            'status' => ServerDatabaseEngine::STATUS_FAILED,
            'error_message' => __('Stopped by operator — reverting partial install.'),
        ]);

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_uninstall',
            __('Reverting :engine install on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        UninstallDatabaseEngineJob::dispatch($row->id, auth()->id());

        $this->toastSuccess(__('Stopping :engine install and reverting — progress shows in the banner above.', [
            'engine' => $engineLabel,
        ]));
    }

    /**
     * Queue an uninstall for the engine identified by tab name. Tracks the underlying row by
     * engine + server so memcached-style swaps don't accidentally drop the wrong row.
     */
    public function uninstallDatabaseEngine(string $engine): void
    {
        $this->authorize('update', $this->server);

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if (! $row) {
            $this->toastError(__('No :engine engine to uninstall.', ['engine' => $engine]));

            return;
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_uninstall',
            __('Uninstalling :engine on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        UninstallDatabaseEngineJob::dispatch($row->id, auth()->id());
        $this->toastSuccess(__('Uninstalling :engine — progress shows in the banner above.', ['engine' => $engineLabel]));
    }

    /**
     * Activate (start + enable at boot) or deactivate (stop + disable at boot) an
     * installed database engine. Dispatches {@see ToggleDatabaseEngineActivationJob}
     * which runs the systemctl change over SSH and flips the row to RUNNING/STOPPED.
     * The daemon stays installed — data and binaries are untouched.
     */
    public function setDatabaseEngineActivation(string $engine, bool $activate): void
    {
        $this->authorize('update', $this->server);

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if (! $row) {
            $this->toastError(__('No :engine engine on this server.', ['engine' => $engine]));

            return;
        }

        // Only toggle between the two resting states — never interrupt an install,
        // uninstall, or a failed row (those have their own recovery actions).
        if (! in_array($row->status, [ServerDatabaseEngine::STATUS_RUNNING, ServerDatabaseEngine::STATUS_STOPPED], true)) {
            $this->toastError(__(':engine is busy — wait for the current operation to finish.', ['engine' => $engine]));

            return;
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;

        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_activation',
            $activate
                ? __('Activating :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name])
                : __('Deactivating :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name]),
        );

        // Optimistically flip the pill so the UI reflects intent immediately; the
        // job confirms (or reverts via its failure path) once SSH completes.
        $row->update([
            'status' => $activate ? ServerDatabaseEngine::STATUS_RUNNING : ServerDatabaseEngine::STATUS_STOPPED,
        ]);

        ToggleDatabaseEngineActivationJob::dispatch($row->id, $activate, auth()->id());

        $this->toastSuccess(
            $activate
                ? __('Activating :engine — progress shows in the banner above.', ['engine' => $engineLabel])
                : __('Deactivating :engine — progress shows in the banner above.', ['engine' => $engineLabel])
        );
    }

    /**
     * Enable or disable remote access (public/CIDR-gated port exposure) for a
     * database engine. Dispatches {@see ToggleDatabaseEngineRemoteAccessJob} which
     * updates listen_addresses / pg_hba / bind-address over SSH, restarts the
     * service, and syncs the UFW-backed firewall rule.
     */
    public function toggleDatabaseEngineRemoteAccess(string $engine, bool $enable): void
    {
        $this->authorize('update', $this->server);

        if (! DatabaseEngineInstallScripts::supportsRemoteAccess($engine)) {
            $this->toastError(__('Remote access configuration is not supported for :engine.', ['engine' => $engine]));

            return;
        }

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('status', ServerDatabaseEngine::STATUS_RUNNING)
            ->first();

        if (! $row) {
            $this->toastError(__(':engine is not running on this server.', ['engine' => $engine]));

            return;
        }

        $allowedFrom = $enable ? trim($this->remote_access_allowed_from) : '';

        if ($enable) {
            // Require an explicit trusted source — never silently open the engine
            // port to the whole internet on a blank field.
            if ($allowedFrom === '') {
                $this->addError('remote_access_allowed_from', __('Enter the CIDR allowed to connect (e.g. 10.0.0.0/8 or your app server IP/32). Leave remote access off to keep the port closed.'));

                return;
            }

            // Basic CIDR sanity — must look like x.x.x.x/n or x::/n.
            if (! $this->isValidRemoteCidr($allowedFrom)) {
                $this->addError('remote_access_allowed_from', __('Enter a valid CIDR (e.g. 10.0.0.0/8, 203.0.113.5/32).'));

                return;
            }
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];

        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_remote_access',
            $enable
                ? __('Enabling remote access for :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name])
                : __('Disabling remote access for :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name]),
        );

        // Optimistically update the row so the UI flips immediately.
        $row->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $allowedFrom : null,
        ]);

        ToggleDatabaseEngineRemoteAccessJob::dispatch($row->id, $enable, $allowedFrom, auth()->id());

        $this->toastSuccess(
            $enable
                ? __('Enabling remote access for :engine — progress shows in the banner above.', ['engine' => $engineLabel])
                : __('Disabling remote access for :engine — progress shows in the banner above.', ['engine' => $engineLabel])
        );
    }

    /**
     * Enable or disable remote network access for a specific database.
     * Updates pg_hba (postgres) or bind-address + GRANT (mysql/mariadb) over SSH,
     * then syncs the engine-level UFW rule.
     */
    public function toggleDatabaseNetworking(string $databaseId, bool $enable): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->find($databaseId);

        if (! $db) {
            $this->toastError(__('Database not found.'));

            return;
        }

        if (! DatabaseEngineInstallScripts::supportsRemoteAccess($db->engine)) {
            $this->toastError(__('Remote access is not supported for :engine.', ['engine' => $db->engine]));

            return;
        }

        $allowedFrom = $enable ? trim($this->db_networking_allowed_from[$databaseId] ?? '') : '';

        if ($enable) {
            // Require an explicit trusted source — never silently open the port
            // to the whole internet on a blank field.
            if ($allowedFrom === '') {
                $this->addError('db_networking_allowed_from.'.$databaseId, __('Enter the CIDR allowed to connect (e.g. 10.0.0.0/8 or your app server IP/32). Leave remote access off to keep the port closed.'));

                return;
            }
            if (! $this->isValidRemoteCidr($allowedFrom)) {
                $this->addError('db_networking_allowed_from.'.$databaseId, __('Enter a valid CIDR (e.g. 10.0.0.0/8, 203.0.113.5/32).'));

                return;
            }
        }

        // Optimistically update so the UI flips immediately.
        $db->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $allowedFrom : null,
        ]);

        ToggleDatabaseNetworkingJob::dispatch($databaseId, $enable, $allowedFrom, auth()->id());

        $this->toastSuccess(
            $enable
                ? __('Enabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
                : __('Disabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
        );
    }

    private function isValidRemoteCidr(string $value): bool
    {
        if ($value === '' || $value === 'any') {
            return false;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));
        foreach ($parts as $part) {
            if (! str_contains($part, '/')) {
                return false;
            }
            [$ip, $prefix] = explode('/', $part, 2);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            if (! is_numeric($prefix) || (int) $prefix < 0 || (int) $prefix > 128) {
                return false;
            }
        }

        return true;
    }

    /**
     * Seed a queued ConsoleAction on the engine row before dispatch so the banner
     * appears immediately (mirrors webserver switch + site config apply).
     */
    protected function seedQueuedDatabaseEngineConsoleAction(
        ServerDatabaseEngine $row,
        string $kind,
        string $label,
    ): ConsoleAction {
        ConsoleAction::query()
            ->where('subject_type', $row->getMorphClass())
            ->where('subject_id', $row->getKey())
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $row->getMorphClass())
            ->where('subject_id', $row->getKey())
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $row->getMorphClass(),
            'subject_id' => $row->getKey(),
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    /**
     * Resolve engine capabilities off the render path. Fired by wire:init so the
     * workspace paints immediately and the engine badges / create buttons appear
     * once the single SSH probe (cached) returns.
     */
    public function loadCapabilities(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('view', $this->server);

        try {
            $this->capabilities_state = $capabilities->forServer($this->server);
        } catch (\Throwable) {
            // SSH timeout / key issues leave badges off; the user can still create
            // databases from Basics — provisioner errors surface there.
            $this->capabilities_state = DatabaseWorkspaceEngines::defaultCapabilities();
        }

        $this->capabilitiesLoaded = true;
    }

    /**
     * Resolve database drift off the render path. Fired by wire:init on the
     * Connections subtab; the drift analyzer makes several SSH round-trips, so
     * keeping it here means it never blocks first paint.
     */
    public function loadDriftSnapshot(ServerDatabaseDriftAnalyzer $driftAnalyzer): void
    {
        $this->authorize('view', $this->server);

        if (! in_array($this->workspace_tab, DatabaseWorkspaceEngines::MANAGEABLE, true)) {
            $this->drift_loaded = true;

            return;
        }

        try {
            $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
            $this->driftProbeErrorNotified = false;
        } catch (\Throwable $e) {
            $message = $this->friendlyDatabaseWorkspaceError(
                $e,
                __('Dply could not connect to the server to compare database drift.')
            );
            if (! $this->driftProbeErrorNotified) {
                $this->toastError($message);
                $this->driftProbeErrorNotified = true;
            }
        }

        $this->drift_loaded = true;
    }

    public function refreshDatabaseCapabilities(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);

        // Seed ServerDatabaseEngine rows for any engines running on the server that
        // dply doesn't have a record for yet (e.g. installed during provisioning or
        // manually via SSH — the "provision seeding gap").
        $detected = $capabilities->probe($this->server);
        $this->capabilities_state = $detected;
        $this->capabilitiesLoaded = true;
        $seededEngines = [];
        foreach ($detected as $engine => $running) {
            if (! $running || $engine === 'sqlite') {
                continue;
            }
            $exists = ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->where('engine', $engine)
                ->exists();
            if (! $exists) {
                ServerDatabaseEngine::query()->create([
                    'server_id' => $this->server->id,
                    'engine' => $engine,
                    'status' => ServerDatabaseEngine::STATUS_RUNNING,
                    'is_default' => ServerDatabaseEngine::query()->where('server_id', $this->server->id)->doesntExist(),
                    'port' => ServerDatabaseEngine::defaultPortFor($engine),
                ]);
                $seededEngines[] = $engine;
            }
        }

        if ($seededEngines !== []) {
            $labels = implode(', ', array_map(fn ($e) => ucfirst($e), $seededEngines));
            $this->toastSuccess(__('Rechecked engines — adopted :engines as already installed.', ['engines' => $labels]));
        } else {
            $this->toastSuccess(__('Rechecked the server for database engines.'));
        }
    }

    public function runDriftAnalysis(
        ServerDatabaseDriftAnalyzer $driftAnalyzer,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
        $this->drift_loaded = true;
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DRIFT_CHECK, [], auth()->user());
        $this->toastSuccess(__('Drift analysis updated.'));
    }
}
