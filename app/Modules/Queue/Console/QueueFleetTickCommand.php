<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\FleetReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sizes every managed queue fleet, once a minute.
 *
 * The tick is the steady-state loop, not the latency path: an idle fleet is
 * woken by the push itself (see {@see FleetReconciler::wake()}), so a job
 * never waits on this. What the tick is for is everything a push cannot
 * observe — a backlog that grew while workers were already running, a burst
 * that ended, a container that died silently.
 */
class QueueFleetTickCommand extends Command
{
    protected $signature = 'dply:queue:fleet-tick {--fleet= : Reconcile one fleet by id}';

    protected $description = 'Size managed queue worker fleets against the work waiting on their queues.';

    public function handle(FleetReconciler $reconciler): int
    {
        $fleets = ManagedQueueFleet::query()
            ->when($this->option('fleet'), fn ($q) => $q->whereKey($this->option('fleet')))
            ->with('namespace')
            ->get();

        $changed = 0;

        foreach ($fleets as $fleet) {
            try {
                $decision = $reconciler->reconcile($fleet);
            } catch (Throwable $e) {
                // One wedged fleet must not stop the others from being sized;
                // a queue that stops draining is the failure this command
                // exists to prevent.
                Log::warning('queue.fleet.tick_failed', [
                    'fleet_id' => $fleet->id,
                    'error' => $e->getMessage(),
                ]);

                $this->components->error($fleet->queue.': '.$e->getMessage());

                continue;
            }

            if ($decision->isChange()) {
                $changed++;
            }

            $this->components->twoColumnDetail(
                $fleet->queue.' ('.$decision->current.' → '.$decision->desired.')',
                $decision->reason,
            );
        }

        $this->info(sprintf('Reconciled %d fleet(s), %d resized.', $fleets->count(), $changed));

        return self::SUCCESS;
    }
}
