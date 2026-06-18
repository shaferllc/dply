<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\InstallCacheServiceJob;
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
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsCacheServiceActions
{


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
