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
 *   4. Reconciles the row's `status` with what the probe found — the column
 *      is otherwise written only at install time, so a row could claim
 *      "Running" forever after the daemon (or its whole package) was removed
 *      out-of-band. A PONG upgrades stopped→running; no PONG *plus* systemd
 *      confirming the unit is missing/inactive downgrades running→stopped.
 *      No PONG with the unit still active is left alone (AUTH mismatch and
 *      friends — the hints cover those).
 *   5. Flips the row to COMPLETED with the captured output.
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

                // Only revive terminal states — an in-flight install/uninstall owns the
                // column and flipping it mid-run would fight the job that's writing it.
                if (in_array($row->status, [ServerCacheService::STATUS_STOPPED, ServerCacheService::STATUS_FAILED], true)) {
                    $previousStatus = $row->status;
                    $row->update(['status' => ServerCacheService::STATUS_RUNNING, 'error_message' => null]);
                    $emit->step('cache', sprintf('Row status updated %s → running to match the live probe.', $previousStatus));
                }
            } else {
                $emit->warn('No PONG from '.$row->engine.' on port '.(int) $row->port.'.', 'cache');

                $unitState = $capabilities->instanceUnitState($row->server, $row->engine);
                if ($unitState === 'not-found') {
                    // No systemd unit under ANY of the engine's candidate names means the
                    // engine isn't installed — "Stopped" would offer Start/Restart buttons
                    // for a daemon that doesn't exist. FAILED tells the truth, surfaces the
                    // message on the card, and unlocks "Force remove row" (which refuses
                    // running/stopped rows) so the operator can clear the phantom row.
                    $emit->warn(sprintf('systemd has no unit for %s on this host — the engine is not installed (the original install likely failed, or the package was removed out-of-band).', $row->engine), 'cache');

                    if (in_array($row->status, [ServerCacheService::STATUS_RUNNING, ServerCacheService::STATUS_STOPPED], true)) {
                        $row->update([
                            'status' => ServerCacheService::STATUS_FAILED,
                            'error_message' => sprintf('%s is not installed on this host: no systemd unit found. The original install likely failed silently, or the package was removed outside dply. Click "Reinstall" to run the install again, or "Force remove row" to clear this entry without touching the server.', ucfirst($row->engine)),
                            'version' => null,
                        ]);
                        $emit->step('cache', 'Row marked failed (not installed) — click "Reinstall" to run the install again, or "Force remove row" to clear it.');
                    }
                } elseif (in_array($unitState, ['inactive', 'failed'], true)) {
                    $emit->step('cache', sprintf('systemd reports the %s unit as %s.', $row->engine, $unitState));

                    if ($row->status === ServerCacheService::STATUS_RUNNING) {
                        $row->update(['status' => ServerCacheService::STATUS_STOPPED]);
                        $emit->step('cache', 'Row status downgraded running → stopped — the "Running" badge was stale install-time state.');
                    }
                } else {
                    // Unit active (or SSH couldn't tell): the daemon may be fine and the
                    // probe blocked for a softer reason — don't touch the status.
                    $emit->step('cache', 'Common causes: AUTH password mismatch (probe reads the row password automatically), engine not listening on the configured port, in-host firewall, or *-cli binary not on the SSH user\'s PATH.');
                }
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
