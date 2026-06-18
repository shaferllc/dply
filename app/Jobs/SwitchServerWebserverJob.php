<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\BuildsSwitchSiteConfigs;
use App\Jobs\Concerns\BuildsWebserverInstallScripts;
use App\Jobs\Concerns\DiagnosesWebserverSwitch;
use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Jobs\Concerns\RunsWebserverSwitchStages;
use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\User;
use App\Services\Certificates\CertificateRequestService;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Notifications\ServerWebserverNotificationDispatcher;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Services\Servers\OpenLiteSpeedHttpdConfigBuilder;
use App\Services\Servers\OpenLiteSpeedHttpdConfigPreserver;
use App\Services\Servers\OpenLiteSpeedTlsConfigurator;
use App\Services\Servers\WebserverStatsEndpointTemplates;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Switch a server's webserver from `from` → `to` via parallel install on :8080,
 * site provisioning under the new webserver, validation, then service-swap to :80.
 *
 * Drives the live progress banner on `/servers/{srv}/manage/web`. The staged
 * structure mirrors the design we locked in via /grill-me:
 *
 *   1. install      — apt/package the target webserver (no downtime, on :8080)
 *   2. provision    — regenerate per-site configs under the new webserver
 *   3. validate     — issue a test request through the new webserver on :8080
 *   4. cutover      — stop old, bind new to :80 (~600ms blip)
 *   5. disable_old  — stop+disable old webserver (kept installed for rollback)
 *
 * Pre-cutover failures auto-rollback the work done so far (uninstall, unprovision,
 * kill the new daemon on :8080); post-cutover failures surface in the banner but
 * don't auto-revert — the operator gets a manual "Re-bind <old> to :80" button.
 *
 * **Implementation status (v1 scaffold)**: this class is wired into the UI and the
 * console-action banner machinery. The actual remote SSH commands (apt install,
 * config write, systemctl swap) are marked as `executeStage*` stubs that emit
 * placeholder output and complete successfully so the end-to-end UX is testable.
 * The follow-up PR fills in `app/Services/Servers/Switcher/*` services that
 * the stubs delegate to.
 */
class SwitchServerWebserverJob implements ShouldBeUnique, ShouldQueue
{
    use BuildsSwitchSiteConfigs;
    use BuildsWebserverInstallScripts;
    use DiagnosesWebserverSwitch;
    use Dispatchable;
    use InteractsWithQueue;
    use PrivilegedRemoteFileWrites;
    use Queueable;
    use RunsWebserverSwitchStages;
    use SerializesModels;
    use WritesConsoleAction;

    public int $tries = 1; // No retries — failure surfaces via the banner.

    public int $timeout = 600; // 10-minute cap; the cutover should finish well under this.

    public function __construct(
        public string $serverId,
        public string $target,
        public bool $tlsToCaddy = false,
        public ?string $userId = null,
    ) {}

    /**
     * Unique constraint so two operators can't race-trigger concurrent switches
     * on the same server. The ConsoleAction lock in WorkspaceManage backstops
     * this at the UI level.
     */
    public function uniqueId(): string
    {
        return 'webserver_switch_'.$this->serverId;
    }

    /**
     * Short lock window. The lock only needs to cover the dispatch race —
     * the UI's `hasInflightWebserverSwitch()` ConsoleAction check is the
     * canonical guard against double-trigger. Keeping uniqueFor close to
     * the worker poll interval means a worker SIGKILL (which skips the
     * normal lock release) only blocks the next dispatch by ~60s instead
     * of the full job timeout.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    protected function consoleSubject(): Model
    {
        return Server::findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'webserver_switch';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ServerWebserverNotificationDispatcher $notifications): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $actor = $this->userId !== null ? User::find($this->userId) : null;
        $emitter = $this->beginConsoleAction();
        $startedAt = microtime(true);
        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));

        // Re-run preflight defensively. The modal already filtered out blocked
        // targets, but the server state could have changed (a site was created
        // with an incompatible runtime) between modal-open and worker-pickup.
        $preflight = app(WebserverSwitchPreflight::class)->plan($server, $this->target);
        if ($preflight['blocker'] !== null) {
            $emitter->error('Preflight blocker: '.$preflight['blocker']['label']);
            $this->failConsoleAction($preflight['blocker']['label']);
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCH_FAILED, [
                'reason' => 'preflight_blocker',
                'blocker' => $preflight['blocker'],
            ], $startedAt);

            $notifications->notify($server, 'engine_switch_failed', [
                __('Switch: :from → :to', ['from' => $from, 'to' => $this->target]),
                __('Reason: :reason', ['reason' => (string) $preflight['blocker']['label']]),
            ], $actor, ['from' => $from, 'to' => $this->target, 'reason' => 'preflight_blocker']);

            return;
        }

        // Staged execution — fail-fast pre-cutover with per-stage rollback.
        try {
            $emitter->info(sprintf('[install]   installing %s on :8080…', $this->target));
            $this->executeStageInstall($server, $emitter);

            $emitter->info(sprintf('[provision] regenerating %d site config(s) under %s', $preflight['sites_affected'], $this->target));
            $this->executeStageProvision($server, $preflight);

            $emitter->info('[validate]  checking new webserver responds on :8080');
            $this->executeStageValidate($server);

            $emitter->info(sprintf('[cutover]   service-swap: stop %s, bind %s to :80', $from, $this->target));
            $this->executeStageCutover($server, $from);

            $emitter->info(sprintf('[finalize]  stop+disable %s (kept installed)', $from));
            $this->executeStageDisableOld($server, $from);

            // Persist the new webserver as the server's truth.
            $meta = $server->meta;
            $meta['webserver'] = $this->target;
            $server->update(['meta' => $meta]);

            // Re-probe systemd inventory so meta.manage_units reflects the
            // new daemon's active_state + unit_file_state. Without this the
            // webserver workspace shows stale state from the OLD engine for
            // up to the next scheduled probe — see the engine-Overview filter
            // that hides Start when daemon is active, etc.
            SyncServerSystemdServicesJob::dispatch($server->id);

            $this->reconcileSitesAfterSwitch($server, $this->target, $from);

            if ($this->target === 'openlitespeed') {
                try {
                    app(OpenLiteSpeedTlsConfigurator::class)->syncServer($server->fresh());
                    $emitter->info('[tls]       applied OpenLiteSpeed HTTPS listeners for existing certificates');
                } catch (\Throwable $e) {
                    $emitter->info('[tls]       HTTPS listener sync skipped — '.$e->getMessage());
                }
            }

            $emitter->info('Done.');
            $this->completeConsoleAction();
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCHED, [
                'tls_opt_in' => $this->tlsToCaddy,
                'sites_affected' => $preflight['sites_affected'],
                'site_ids' => Site::query()->where('server_id', $server->id)->pluck('id')->all(),
            ], $startedAt, ServerWebserverAuditEvent::RESULT_SUCCESS);

            $notifications->notify($server, 'engine_switched', [
                __('Switch: :from → :to', ['from' => $from, 'to' => $this->target]),
                __('Sites reconfigured: :count', ['count' => (int) $preflight['sites_affected']]),
            ], $actor, ['from' => $from, 'to' => $this->target, 'sites_affected' => $preflight['sites_affected']]);
        } catch (\Throwable $e) {
            $emitter->error('Switch failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $this->recordAudit($server, $from, ServerWebserverAuditEvent::ACTION_SWITCH_FAILED, [
                'reason' => $e->getMessage(),
            ], $startedAt);

            $notifications->notify($server, 'engine_switch_failed', [
                __('Switch: :from → :to', ['from' => $from, 'to' => $this->target]),
                __('Reason: :reason', ['reason' => $e->getMessage()]),
            ], $actor, ['from' => $from, 'to' => $this->target, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Framework-level failure path — invoked when the worker raises an
     * exception OUTSIDE handle()'s try/catch, most commonly
     * MaxAttemptsExceededException when the Redis `retry_after` fires before
     * the worker's own `timeout` and the job is re-dispatched (attempts=2
     * with tries=1). A fresh job instance is constructed for failed(), so we
     * can't use $this->consoleRunId — look the row up by subject + kind.
     */
    public function failed(\Throwable $e): void
    {
        // Release the ShouldBeUnique lock explicitly. Laravel's normal
        // release path runs in CallQueuedHandler::call() — that path
        // doesn't execute when the worker is SIGKILL'd by --timeout, so
        // the lock would otherwise sit for the full uniqueFor() window
        // blocking every retry. failed() does run after a SIGKILL via
        // Horizon's lost-job detection, so this is the right hook.
        app(UniqueLock::class)->release($this);

        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $action = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        $message = sprintf('Job failed before completing: %s', $e->getMessage());
        if ($action !== null) {
            $action->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($message, 0, 2000),
            ]);
        }

        $from = strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => ServerWebserverAuditEvent::ACTION_SWITCH_FAILED,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Webserver switch from :from to :to (worker failure)', [
                'from' => $from !== '' ? $from : '(none)',
                'to' => $this->target,
            ]),
            'payload' => [
                'from' => $from,
                'to' => $this->target,
                'reason' => 'worker_failed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ],
            'result_status' => ServerWebserverAuditEvent::RESULT_FAILURE,
        ]);
    }


}
