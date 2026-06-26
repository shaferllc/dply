<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerReleaseHygieneScanner;
use App\Support\ServerReleaseHygieneScanStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Interactive (operator-triggered) release-hygiene scan. Runs the same SSH scan as
 * the daily {@see RunServerReleaseHygieneScanJob}, but unconditionally (no
 * subscription gate) and streams progress + a terminal result into
 * {@see ServerReleaseHygieneScanStatus} so the "Scan disk" button can dispatch and
 * poll instead of running SSH inline — the scan can take ~120s, well past PHP's
 * request cap, which previously left the button stuck on "Scanning…".
 *
 * Mirrors {@see ScanServerLiveCertsJob}.
 */
class ScanServerReleaseHygieneJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $serverId, public ?string $actorId = null)
    {
        $queue = config('server_manage.remote_task_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(ServerReleaseHygieneScanner $scanner): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            ServerReleaseHygieneScanStatus::markResult($this->serverId, false, __('Server not found.'));

            return;
        }

        $actor = $this->actorId !== null ? User::find($this->actorId) : null;

        try {
            $scanner->scanAndNotify(
                $server,
                $actor,
                fn (string $chunk): mixed => ServerReleaseHygieneScanStatus::append($this->serverId, $chunk),
            );
            ServerReleaseHygieneScanStatus::markResult($this->serverId, true, null);
        } catch (\Throwable $e) {
            ServerReleaseHygieneScanStatus::markResult($this->serverId, false, $e->getMessage());
        }
    }

    /** Resolve a polling UI to the error state after a hard failure. */
    public function failed(\Throwable $e): void
    {
        ServerReleaseHygieneScanStatus::markResult($this->serverId, false, $e->getMessage());
    }
}
