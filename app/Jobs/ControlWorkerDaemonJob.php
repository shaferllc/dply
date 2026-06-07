<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\WorkerPools\WorkerDaemonBackend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Start / stop / restart / ensure the queue-worker (Horizon or queue:work)
 * systemd units for a site — the per-server daemon control behind the worker
 * pool's worker buttons.
 *
 * Unlike {@see ProvisionSiteSystemdUnitsJob} (which short-circuits for php/static
 * web sites because FPM is implicit), this drives the WORKER daemons directly via
 * {@see \App\Services\WorkerPools\WorkerDaemonBackend}, so a Laravel (php) site's
 * Horizon daemon is managed even though its web tier is FPM. The backend runs the
 * daemons under the pool's chosen process manager (systemd or supervisor) and
 * tears down the other. Streams to the site's `systemd` console banner.
 *
 *   action 'ensure'  → backend->ensure() (provision chosen PM, tear down other)
 *   action 'start' | 'stop' | 'restart' → backend->control() on the active PM
 */
class ControlWorkerDaemonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $siteId,
        public string $action = 'ensure',
        public ?string $userId = null,
        public ?string $arg = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'systemd';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(WorkerDaemonBackend $backend, ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server', 'processes')->find($this->siteId);
        if ($site === null) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            // Horizon management commands run via artisan in the app dir (the
            // operator's "access to Horizon" on a worker box — pause/resume the
            // queue, gracefully restart, snapshot metrics, or read status).
            if (str_starts_with($this->action, 'horizon:')) {
                $allowed = ['horizon:pause', 'horizon:continue', 'horizon:terminate', 'horizon:snapshot', 'horizon:status'];
                $cmd = in_array($this->action, $allowed, true) ? $this->action : 'horizon:status';
                $dir = rtrim($site->effectiveEnvDirectory(), '/');
                $emit->step('horizon', $cmd.' (in '.$dir.')');
                if ($site->server === null) {
                    throw new \RuntimeException('Member server is not available.');
                }
                $out = $exec->runInlineBash(
                    $site->server,
                    'worker-pool:'.$cmd,
                    'cd '.escapeshellarg($dir).' && php artisan '.escapeshellarg($cmd).' 2>&1',
                    timeoutSeconds: 60,
                    asRoot: false,
                );
                $emit(trim((string) $out->buffer) ?: '(no output)', 'info', 'horizon');
                $this->completeConsoleAction();

                return;
            }

            // Failed-job management: retry/forget a specific job (arg = uuid or
            // "all"), or flush them all — run via artisan in the app dir.
            if (in_array($this->action, ['queue:retry', 'queue:forget', 'queue:flush'], true)) {
                $dir = rtrim($site->effectiveEnvDirectory(), '/');
                if ($site->server === null) {
                    throw new \RuntimeException('Member server is not available.');
                }
                $arg = trim((string) $this->arg);
                $needsArg = in_array($this->action, ['queue:retry', 'queue:forget'], true);
                if ($needsArg && $arg === '') {
                    $arg = 'all'; // retry/forget with no id → operate on all
                }
                // Allowlist the argument shape: a UUID or the literal "all".
                if ($arg !== '' && $arg !== 'all' && ! preg_match('/^[0-9a-fA-F-]{8,64}$/', $arg)) {
                    throw new \RuntimeException('Invalid job identifier.');
                }
                $line = 'php artisan '.$this->action.($arg !== '' ? ' '.escapeshellarg($arg) : '');
                $emit->step('queue', $this->action.($arg !== '' ? ' '.$arg : '').' (in '.$dir.')');
                $out = $exec->runInlineBash(
                    $site->server,
                    'worker-pool:'.$this->action,
                    'cd '.escapeshellarg($dir).' && '.$line.' 2>&1',
                    timeoutSeconds: 60,
                    asRoot: false,
                );
                $emit(trim((string) $out->buffer) ?: '(no output)', 'info', 'queue');
                $this->completeConsoleAction();

                return;
            }

            if ($this->action === 'check') {
                if ($site->server === null) {
                    throw new \RuntimeException('Member server is not available.');
                }
                $pm = $backend->backendFor($site);
                $cmd = $pm === 'supervisor'
                    ? 'supervisorctl status 2>&1 || true'
                    : 'systemctl list-units "dply-*" --no-pager --output=short 2>&1 || true';
                $emit->step($pm, 'checking worker backend status');
                $out = $exec->runInlineBash($site->server, 'worker-pool:check', $cmd, timeoutSeconds: 15, asRoot: false);
                $emit(trim((string) $out->buffer) ?: '(no output)', 'info', $pm);
                $this->completeConsoleAction();

                return;
            }

            if ($this->action === 'ensure') {
                // Guarantee redis-cli is present so the Traffic tab can read the
                // Redis backend (the app uses phpredis, so redis-tools may be
                // absent). Idempotent + best-effort — never blocks provisioning.
                if ($site->server !== null) {
                    $emit->step('systemd', 'ensuring redis-cli (redis-tools) is installed');
                    try {
                        $rc = $exec->runInlineBash(
                            $site->server,
                            'worker-pool:ensure-redis-cli',
                            'command -v redis-cli >/dev/null 2>&1 && { echo "redis-cli already present"; exit 0; }; '
                            .'sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y redis-tools 2>&1 | tail -n3 '
                            .'|| sudo -n bash -lc "apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y redis-tools" 2>&1 | tail -n3 || echo "could not install redis-tools (non-fatal)"',
                            timeoutSeconds: 120,
                            asRoot: false,
                        );
                        $emit(trim((string) $rc->buffer) ?: '(no output)', 'info', 'systemd');
                    } catch (\Throwable $e) {
                        $emit->warn('redis-cli install skipped: '.$e->getMessage(), 'systemd');
                    }
                }

                $emit->step('systemd', 'writing + starting worker daemons');
                $result = $backend->ensure($site);
                $emit->success(($result['backend'] === 'supervisor' ? 'supervisor' : 'systemd').': '.$result['detail'], 'systemd');
            } else {
                $emit->step('systemd', $this->action.' worker daemons');
                $out = $backend->control($site, $this->action);
                $emit(trim($out) !== '' ? $out : '(no output)', 'info', 'systemd');
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'systemd');
            $this->failConsoleAction($e->getMessage());
            Log::warning('ControlWorkerDaemonJob failed', [
                'site_id' => $site->id,
                'action' => $this->action,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
