<?php

declare(strict_types=1);

namespace App\Modules\Queue\Jobs;

use App\Models\Server;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\FleetBackendExposure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Open the app's database and Redis to a host a fleet's worker just landed on.
 *
 * Queued rather than inline because placement happens on the push path, which
 * must stay one insert and one runtime call. The host is recorded only once the
 * exposure ran, so a failure is retried at the next placement instead of being
 * remembered as done.
 */
class ExposeFleetBackendsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $fleetId, public string $hostId)
    {
        $this->onQueue('dply-control');
    }

    public function uniqueId(): string
    {
        return $this->fleetId.':'.$this->hostId;
    }

    public function handle(FleetBackendExposure $exposure): void
    {
        $fleet = ManagedQueueFleet::query()->with('namespace.site')->find($this->fleetId);
        $host = Server::query()->find($this->hostId);

        if (! $fleet instanceof ManagedQueueFleet || ! $host instanceof Server) {
            return;
        }

        $result = $exposure->open($fleet, $host);

        $fleet->refresh();
        $meta = is_array($fleet->meta) ? $fleet->meta : [];
        $meta['exposed_hosts'] = array_values(array_unique([...(array) ($meta['exposed_hosts'] ?? []), $this->hostId]));
        $meta['exposure'][$this->hostId] = ['at' => now()->toIso8601String(), 'targets' => $result];
        $fleet->forceFill(['meta' => $meta])->save();
    }
}
