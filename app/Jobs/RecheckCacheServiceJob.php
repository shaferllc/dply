<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\ServerCacheService;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Background runner for the Caches workspace "Recheck" button. The Livewire
 * handler {@see App\Livewire\Servers\WorkspaceCaches::recheckCacheServiceInstance}
 * calls `seedConsoleActionRun()` before dispatching this job, so the banner at
 * the top of the page surfaces with status=QUEUED the moment the operator
 * clicks — they see "Probing…" instead of staring at a still page while the
 * SSH probe runs.
 *
 * This job:
 *   1. Flips the ConsoleAction row to RUNNING (banner updates via wire:poll).
 *   2. Runs the SSH probe via the existing capabilities support class.
 *   3. Emits a `success` or `warn` line with the result, plus diagnostic
 *      hints when the probe fails.
 *   4. Flips the row to COMPLETED with the captured output.
 */
class RecheckCacheServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $consoleActionId,
        public string $cacheServiceId,
    ) {
        $q = config('server_cache.recheck_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(ServerCacheServiceHostCapabilities $capabilities): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        if ($row === null || $action === null || $row->server === null) {
            return;
        }

        // Flip to RUNNING so the banner shows the active state. wire:poll picks
        // this up on the next cycle.
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);

        try {
            $emit->step('cache', sprintf('Probing %s on port %d (SSH → %s-cli ping)', $row->engine, (int) $row->port, $row->engine));
            $reachable = $capabilities->probeInstance($row->server, $row->engine, (int) $row->port);
            $capabilities->forget($row->server);

            if ($reachable) {
                $emit->success('cache', sprintf('PONG — %s instance %s on port %d is reachable.', $row->engine, $row->name, (int) $row->port));
            } else {
                $emit->warn('No PONG from '.$row->engine.' on port '.(int) $row->port.'.', 'cache');
                $emit->step('cache', 'Common causes: AUTH password mismatch (probe reads the row password automatically), engine not listening on the configured port, in-host firewall, or *-cli binary not on the SSH user\'s PATH.');
                $emit->step('cache', 'Click "Debug" next to this card for a full systemctl + ss + journal + ping dump.');
            }

            DB::table('console_actions')->where('id', $this->consoleActionId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $this->consoleActionId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }
    }
}
