<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\ServerCacheService;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceInstallScripts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Background runner for the Caches workspace "Status" and "Logs" buttons. The
 * Livewire handler seeds a ConsoleAction row first so the banner at the top
 * of the page appears in QUEUED state the moment the operator clicks — they
 * see "Running…" right away instead of staring at a frozen page while SSH
 * runs.
 *
 * `$view`:
 *   - 'status' → systemctl status <unit> (Status button)
 *   - 'logs'   → journalctl -u <unit> -n 200 (Logs button)
 *
 * Replaces the previous modal flow (`showCacheStatusModal`) — operators now
 * see the result in the banner like every other action, and can hit "Open"
 * to expand the full output in a side panel.
 */
class StatusCacheServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $consoleActionId,
        public string $cacheServiceId,
        public string $view, // 'status' | 'logs'
    ) {
        $q = config('server_cache.status_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(ExecuteRemoteTaskOnServer $executor): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        if ($row === null || $action === null || $row->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);

        try {
            $unit = CacheServiceInstallScripts::instanceServiceUnit($row->engine, $row->name);
            $unitArg = escapeshellarg($unit);

            // Same shape used previously by the modal. Wrapping in `(...); exit 0`
            // so a non-zero systemctl/journalctl exit code (e.g. unit not loaded
            // yet) still surfaces useful output instead of throwing.
            $script = $this->view === 'logs'
                ? '(journalctl --no-pager --output=short-iso -u '.$unitArg.' -n 200 2>&1); exit 0'
                : '(systemctl status '.$unitArg.' --no-pager -l 2>&1); exit 0';

            $emit->step('cache', $this->view === 'logs'
                ? sprintf('journalctl -u %s -n 200', $unit)
                : sprintf('systemctl status %s', $unit));

            $output = $executor->runInlineBash(
                $row->server,
                'cache-service:'.$this->view.':'.$row->engine.':'.$row->name,
                $script,
                timeoutSeconds: 30,
                asRoot: true,
            );

            $buffer = trim($output->buffer);
            if ($buffer === '') {
                $emit->warn('No output captured.', 'cache');
            } else {
                // Cap at 8KB on the way into the emitter so a huge journal
                // doesn't bloat the row. The banner's "Open" affordance pulls
                // the same data so what the operator sees expanded matches.
                $tail = mb_substr($buffer, -8_000);
                foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
                    if ($line !== '') {
                        $emit($line, ConsoleAction::LEVEL_INFO, 'cache');
                    }
                }
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
