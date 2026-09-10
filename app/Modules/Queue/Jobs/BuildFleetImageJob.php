<?php

declare(strict_types=1);

namespace App\Modules\Queue\Jobs;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\FleetImageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Build a fleet's worker image from the site's repository.
 *
 * Queued because a cold `composer install` plus `npm ci` runs for minutes and
 * PHP's request timeout is 30 seconds — the panel polls the state this writes
 * rather than holding a request open.
 *
 * Unique per fleet: double-clicking Build must not start two `docker build`
 * runs against the same workspace directory on the same host, which would have
 * them overwrite each other's checkout mid-build.
 */
class BuildFleetImageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Comfortably past the builder's own 1800s build ceiling. */
    public int $timeout = 2100;

    public function __construct(public string $fleetId)
    {
        $this->onQueue('dply-control');
    }

    public function uniqueId(): string
    {
        return $this->fleetId;
    }

    public function handle(FleetImageBuilder $builder): void
    {
        $fleet = ManagedQueueFleet::query()->with('namespace.site')->find($this->fleetId);

        if (! $fleet instanceof ManagedQueueFleet) {
            return;
        }

        $this->writeState($fleet, ['state' => 'building', 'started_at' => now()->toIso8601String()]);

        try {
            $tag = $builder->build($fleet);
        } catch (Throwable $e) {
            $this->settleFailure($e, $fleet);

            return;
        }

        // The column is what the reconciler reads; the meta is what the panel
        // shows. Written together so a finished build cannot report success
        // while workers still start on the previous image.
        $fleet->forceFill(['image' => $tag])->save();

        $this->writeState($fleet, [
            'state' => 'ok',
            'finished_at' => now()->toIso8601String(),
            'tag' => $tag,
            'error' => null,
        ]);
    }

    /**
     * A throw the builder did not catch — or the timeout — still has to settle
     * the row. Otherwise the panel shows "building" forever for a build that
     * stopped, which is the failure mode that made the queue-setup banner lie.
     */
    public function failed(Throwable $e): void
    {
        $this->settleFailure($e);
    }

    private function settleFailure(Throwable $e, ?ManagedQueueFleet $fleet = null): void
    {
        $fleet ??= ManagedQueueFleet::query()->find($this->fleetId);

        if (! $fleet instanceof ManagedQueueFleet) {
            return;
        }

        $this->writeState($fleet, [
            'state' => 'failed',
            'finished_at' => now()->toIso8601String(),
            'error' => Str::limit($e->getMessage(), 600),
        ]);
    }

    /**
     * Merge into `meta.image_build` rather than replacing it, so a failure keeps
     * the `started_at` of the run that failed.
     *
     * @param  array<string, mixed>  $changes
     */
    private function writeState(ManagedQueueFleet $fleet, array $changes): void
    {
        $meta = is_array($fleet->meta) ? $fleet->meta : [];
        $existing = is_array($meta['image_build'] ?? null) ? $meta['image_build'] : [];

        $meta['image_build'] = array_merge($existing, $changes);

        $fleet->forceFill(['meta' => $meta])->save();
    }
}
