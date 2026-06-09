<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SchedulerWrapperScript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Propagate a per-scheduler output-capture toggle to the box (schedule-page-v2,
 * PR2). Capture is decided on-box by a control file the wrapper checks each
 * tick — so enabling = touch the file (+ lazily re-push a capture-capable
 * wrapper); disabling = remove the file. Nothing is hoarded when off.
 */
class SetSchedulerOutputCaptureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public string $heartbeatId,
        public bool $enabled,
        public string $runId,
    ) {}

    public static function cacheKey(string $runId): string
    {
        return 'scheduler_capture:'.$runId;
    }

    public function handle(ExecuteRemoteTaskOnServer $remote, SchedulerWrapperScript $wrapper): void
    {
        $this->store('running', '');

        $server = Server::query()->find($this->serverId);
        $heartbeat = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->serverId)
            ->whereKey($this->heartbeatId)
            ->first();

        if ($server === null || $heartbeat === null || $heartbeat->site_id === null) {
            $this->store('failed', 'Scheduler or server not found.');

            return;
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            $this->store('failed', 'Server is not ready for SSH.');

            return;
        }

        $captureFile = SchedulerWrapperScript::HEARTBEAT_DIR.'/'.$heartbeat->site_id.'.capture';

        try {
            if ($this->enabled) {
                $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
                if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser) || $deployUser === 'root') {
                    $deployUser = 'dply';
                }
                // Lazily re-push a capture-capable wrapper, then touch the
                // control file so the next tick starts capturing stdout.
                $bash = $wrapper->installBashFragment($deployUser)
                    ."\n".'touch '.escapeshellarg($captureFile)
                    .' && echo "Output capture enabled."';
            } else {
                $bash = 'rm -f '.escapeshellarg($captureFile).' && echo "Output capture disabled."';
            }

            $out = $remote->runInlineBash($server, 'scheduler-capture-toggle', $bash, $this->timeout, false);
            $body = trim((string) $out->getBuffer());
            $this->store(
                $out->getExitCode() === 0 ? 'done' : 'failed',
                $body !== '' ? $body : ($out->getExitCode() === 0 ? '(no output)' : 'Exited with code '.$out->getExitCode()),
            );
        } catch (\Throwable $e) {
            Log::warning('scheduler.capture_toggle.failed', [
                'server_id' => $this->serverId,
                'heartbeat_id' => $this->heartbeatId,
                'enabled' => $this->enabled,
                'error' => $e->getMessage(),
            ]);
            $this->store('failed', $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->store('failed', $exception?->getMessage() ?? 'The capture toggle job failed.');
    }

    private function store(string $status, string $output): void
    {
        Cache::put(self::cacheKey($this->runId), compact('status', 'output'), now()->addMinutes(10));
    }
}
