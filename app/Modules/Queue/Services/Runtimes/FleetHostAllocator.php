<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services\Runtimes;

use App\Models\Server;
use App\Modules\Queue\Models\ManagedQueueWorker;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Chooses which dply-owned machine a worker container lands on.
 *
 * A host opts in through its own meta — there is no way to become a queue
 * fleet host by accident, because the blast radius of a mistake here is
 * customer code running on a machine that was doing something else:
 *
 *     meta.queue_fleet_host = { enabled: true, capacity_mib: 8192 }
 *
 * Placement is most-free-first (spread), not best-fit (pack). Packing wins on
 * density and loses on the case that matters: a fleet scaling up is usually
 * one queue getting busy, and putting all of its workers on one host makes
 * that host's failure the queue's failure.
 */
class FleetHostAllocator
{
    /**
     * Pick a host with room for `$memoryMib`.
     *
     * @throws RuntimeException when no opted-in host has capacity
     */
    public function allocate(int $memoryMib): Server
    {
        $hosts = $this->hosts();

        if ($hosts->isEmpty()) {
            throw new RuntimeException('No queue fleet hosts are configured.');
        }

        $used = $this->committedMemory($hosts->pluck('id')->all());

        $best = null;
        $bestFree = 0;

        foreach ($hosts as $host) {
            $capacity = $this->capacityOf($host);
            $free = $capacity - (int) ($used[$host->id] ?? 0);

            if ($free >= $memoryMib && $free > $bestFree) {
                $best = $host;
                $bestFree = $free;
            }
        }

        if (! $best instanceof Server) {
            throw new RuntimeException(sprintf('No queue fleet host has %d MiB free.', $memoryMib));
        }

        return $best;
    }

    /** @return \Illuminate\Support\Collection<int, Server> */
    public function hosts()
    {
        return Server::query()
            ->whereNotNull('meta')
            ->get()
            ->filter(fn (Server $server) => (bool) data_get($server->meta, 'queue_fleet_host.enabled', false))
            ->values();
    }

    /**
     * Memory already promised to live workers, per host.
     *
     * Read from the worker rows rather than from the hosts themselves: a
     * container that is still starting has not taken its memory yet but is
     * about to, and placing against actual host usage would double-book it.
     *
     * @param  list<string>  $hostIds
     * @return array<string, int>
     */
    private function committedMemory(array $hostIds): array
    {
        if ($hostIds === []) {
            return [];
        }

        return ManagedQueueWorker::query()
            ->live()
            ->whereIn('host_server_id', $hostIds)
            ->groupBy('host_server_id')
            ->select('host_server_id', DB::raw('sum(memory_mib) as committed'))
            ->pluck('committed', 'host_server_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function capacityOf(Server $server): int
    {
        return max(0, (int) data_get($server->meta, 'queue_fleet_host.capacity_mib', 0));
    }
}
