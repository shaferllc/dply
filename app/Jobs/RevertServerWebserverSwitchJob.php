<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\ServerWebserverNotificationDispatcher;
use App\Services\RemoteCli\RiskLevel;
use App\Services\SshConnection;
use App\Support\Servers\CaddyRuntimeOwnership;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Best-effort revert of a stuck/failed webserver switch.
 *
 * Triggered from the "Stop & revert" button on the webserver-switch banner
 * after {@see SwitchServerWebserverJob} has stalled mid-install. Cleans up
 * partial state from the install/provision stages — once cutover happens the
 * new webserver is live on :80 and "revert" means a forward switch back to the
 * original, which the operator runs through the normal switch flow.
 *
 * Each remote step is best-effort: a failure logs through the banner emitter
 * but does not abort subsequent steps, since the operator is already in a
 * recovery scenario and we want to make as much progress as possible. The
 * audit row records overall success/failure based on whether the original
 * webserver ends up bound on :80.
 *
 * Reuses kind=`webserver_switch` so the banner shows the revert progress in
 * the same slot the failed switch occupied — one banner at a time.
 */
class RevertServerWebserverSwitchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $serverId,
        public string $target,
        public string $from,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'webserver_switch_revert_'.$this->serverId;
    }

    /**
     * Short lock window. Same rationale as SwitchServerWebserverJob —
     * the dispatch race is what we're guarding against, not the runtime
     * duration. A SIGKILL'd worker shouldn't block dispatch for 10 min.
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
        $ssh = new SshConnection($server);

        $emitter->info(sprintf('[stop]      stopping %s if running', $this->target));
        $this->bestEffortStopUnit($server, $ssh, $this->target);

        $emitter->info(sprintf('[cleanup]   removing dply %s site configs', $this->target));
        $this->bestEffortRemoveTargetSiteConfigs($server, $ssh, $this->target);

        $emitter->info(sprintf('[uninstall] removing %s package + repo files', $this->target));
        $this->bestEffortUninstall($server, $ssh, $this->target);

        $emitter->info(sprintf('[restore]   ensuring %s is up on :80', $this->from));
        $restored = $this->ensureOriginalUp($server, $ssh, $this->from);

        // Only reset meta if the failed switch had already flipped it (rare —
        // the SwitchServerWebserverJob persists meta only after cutover succeeds).
        // Defensive though: if meta points at the half-installed target, snap it
        // back to `from` so the rest of the UI doesn't lie.
        $meta = is_array($server->meta) ? $server->meta : [];
        if (strtolower((string) ($meta['webserver'] ?? '')) === strtolower($this->target)) {
            $meta['webserver'] = $this->from;
            $server->update(['meta' => $meta]);
        }

        if ($restored) {
            $emitter->info('Reverted.');
            $this->completeConsoleAction();
            $this->recordAudit($server, ServerWebserverAuditEvent::RESULT_SUCCESS, $startedAt);
            $notifications->notify($server, 'switch_reverted', [
                __('Reverted :target back to :from', ['target' => $this->target, 'from' => $this->from]),
            ], $actor, ['from' => $this->from, 'target' => $this->target]);
        } else {
            $msg = sprintf('Could not confirm %s is running on :80 after revert — manual check required.', $this->from);
            $emitter->error($msg);
            $this->failConsoleAction($msg);
            $this->recordAudit($server, ServerWebserverAuditEvent::RESULT_FAILURE, $startedAt, $msg);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Release the ShouldBeUnique lock explicitly so retries aren't
        // blocked for the full uniqueFor() window when the worker was
        // SIGKILL'd. See SwitchServerWebserverJob::failed() for the full
        // rationale.
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

        $message = sprintf('Revert worker failed: %s', $e->getMessage());
        if ($action !== null) {
            $action->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($message, 0, 2000),
            ]);
        }

        $this->recordAudit($server, ServerWebserverAuditEvent::RESULT_FAILURE, microtime(true), $message);
    }

    private function bestEffortRemoveTargetSiteConfigs(Server $server, SshConnection $ssh, string $webserver): void
    {
        if (strtolower($webserver) !== 'caddy') {
            return;
        }

        $paths = Site::query()
            ->where('server_id', $server->id)
            ->get()
            ->map(function (Site $site): string {
                $basename = (string) $site->webserverConfigBasename();

                return '/etc/caddy/sites-enabled/'.$basename.'.caddy';
            })
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return;
        }

        $rm = $paths
            ->map(fn (string $path): string => 'rm -f '.escapeshellarg($path))
            ->implode('; ');
        $ssh->exec($this->privilegedCommand($rm.'; '.CaddyRuntimeOwnership::shell()), 30);
    }

    private function bestEffortStopUnit(Server $server, SshConnection $ssh, string $webserver): void
    {
        $unit = $this->systemdUnitFor($webserver);
        if ($unit === null) {
            return;
        }

        $cmd = sprintf('systemctl stop %s 2>/dev/null || true', escapeshellarg($unit));
        $ssh->exec($this->privilegedCommand($cmd), 30);
    }

    /**
     * apt-get remove the partially installed package and drop any third-party
     * repo files we may have written during install. `apt-get remove` (vs purge)
     * keeps configs around in case the operator wants to retry.
     */
    private function bestEffortUninstall(Server $server, SshConnection $ssh, string $webserver): void
    {
        [$package, $reposToRemove, $extraCleanup] = match (strtolower($webserver)) {
            'nginx' => ['nginx', [], ''],
            'apache' => ['apache2', [], ''],
            'caddy' => ['caddy', [
                '/etc/apt/sources.list.d/caddy-stable.list',
                '/etc/apt/sources.list.d/caddy.list',
            ], ''],
            'openlitespeed' => ['openlitespeed', [
                '/etc/apt/sources.list.d/lst_debian_repo.list',
            ], ''],
            'traefik' => [
                null,
                [],
                // Traefik is a static binary + unit, not an apt package.
                'systemctl disable traefik 2>/dev/null || true; '
                .'rm -f /usr/local/bin/traefik /etc/systemd/system/traefik.service; '
                .'systemctl daemon-reload 2>/dev/null || true',
            ],
            default => [null, [], ''],
        };

        $script = "set +e\n";
        if ($package !== null) {
            $script .= sprintf("DEBIAN_FRONTEND=noninteractive apt-get remove -y %s 2>&1 | tail -n 50\n", escapeshellarg($package));
        }
        foreach ($reposToRemove as $repo) {
            $script .= sprintf("rm -f %s\n", escapeshellarg($repo));
        }
        if ($extraCleanup !== '') {
            $script .= $extraCleanup."\n";
        }
        $script .= "true\n";

        $ssh->exec($this->privilegedCommand($script), 180);
    }

    /**
     * Make sure the original webserver is enabled and running on :80. Returns
     * true when systemd reports it active after the reload.
     */
    private function ensureOriginalUp(Server $server, SshConnection $ssh, string $webserver): bool
    {
        $unit = $this->systemdUnitFor($webserver);
        if ($unit === null) {
            return false;
        }

        $unitArg = escapeshellarg($unit);
        $cmd = sprintf(
            'systemctl enable %1$s 2>/dev/null || true; '
            .'systemctl restart %1$s 2>&1 | tail -n 20; '
            .'systemctl is-active %1$s',
            $unitArg,
        );
        $out = $ssh->exec($this->privilegedCommand($cmd), 60);

        return str_contains($out, 'active') && ! str_contains($out, 'inactive');
    }

    private function privilegedCommand(string $command): string
    {
        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    private function systemdUnitFor(string $webserver): ?string
    {
        return match (strtolower($webserver)) {
            'nginx' => 'nginx',
            'caddy' => 'caddy',
            'apache' => 'apache2',
            'openlitespeed' => 'lshttpd',
            'traefik' => 'traefik',
            default => null,
        };
    }

    private function recordAudit(
        Server $server,
        string $resultStatus,
        float $startedAt,
        ?string $reason = null,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        ServerWebserverAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $this->userId,
            'action' => ServerWebserverAuditEvent::ACTION_ROLLBACK,
            'risk' => RiskLevel::MutatingRecoverable->value,
            'transport' => ServerWebserverAuditEvent::TRANSPORT_WEB,
            'summary' => __('Webserver switch revert: :target → :from (:status)', [
                'target' => $this->target,
                'from' => $this->from,
                'status' => $resultStatus,
            ]),
            'payload' => array_filter([
                'from' => $this->from,
                'to' => $this->target,
                'reason' => $reason,
                'duration_ms' => $durationMs,
            ], static fn ($v) => $v !== null),
            'result_status' => $resultStatus,
        ]);
    }
}
