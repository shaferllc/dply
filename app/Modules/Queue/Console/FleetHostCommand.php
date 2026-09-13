<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Models\Server;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Services\Runtimes\FleetHostAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Opt a server in as a queue fleet host, and read back how far its proof got.
 *
 * Deliberately a command and not a button: a fleet host runs customer code, so
 * becoming one is an operator decision (see {@see FleetHostAllocator}).
 * The proof is the smoke test passing once, then seven days of soak with no
 * worker on the host dying — what `dply:queue:fleet-smoke` stamps and this reads.
 */
class FleetHostCommand extends Command
{
    /** Days a host has to run workers without one failing. */
    public const SOAK_DAYS = 7;

    /** What FleetImageBuilder asks the allocator for per build. */
    public const BUILD_MIB = 2048;

    protected $signature = 'dply:queue:fleet-host
        {server : Server id or name}
        {--capacity= : Opt in, with this much worker memory in MiB}
        {--off : Place no new workers here}';

    protected $description = 'Opt a server in as a queue fleet host, or show its proof status.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = Server::query()->whereKey($needle)->first() ?? Server::query()->where('name', $needle)->first();

        if (! $server instanceof Server) {
            $this->components->error('No server matched "'.$needle.'".');

            return self::FAILURE;
        }

        $meta = is_array($server->meta) ? $server->meta : [];

        if ($this->option('off')) {
            data_set($meta, 'queue_fleet_host.enabled', false);
            $server->forceFill(['meta' => $meta])->save();
            $this->components->info($server->name.' takes no new workers. Running ones finish where they are.');

            return self::SUCCESS;
        }

        if ($this->option('capacity') !== null) {
            $capacity = (int) $this->option('capacity');

            if ($capacity < 256) {
                $this->components->error('Capacity must be at least 256 MiB — one flex worker.');

                return self::FAILURE;
            }

            // FleetImageBuilder places a build against the same capacity as
            // workers, so a build needs this much free or it cannot land here.
            if ($capacity < self::BUILD_MIB + 256) {
                $this->components->warn(sprintf('Builds reserve %d MiB of this — below %d MiB, this host cannot build images at all.', self::BUILD_MIB, self::BUILD_MIB + 256));
            } else {
                $this->components->warn(sprintf('Builds reserve %d MiB of this. Once workers hold more than %d MiB, a deploy’s image build has to wait for them to scale down.', self::BUILD_MIB, $capacity - self::BUILD_MIB));
            }

            data_set($meta, 'queue_fleet_host.enabled', true);
            data_set($meta, 'queue_fleet_host.capacity_mib', $capacity);
            $server->forceFill(['meta' => $meta])->save();
            $this->components->info($server->name.' is a fleet host. Prove it: php artisan dply:queue:fleet-smoke '.$server->name);
        }

        $this->status($server->fresh() ?? $server);

        return self::SUCCESS;
    }

    private function status(Server $server): void
    {
        $host = (array) data_get($server->meta, 'queue_fleet_host', []);

        $this->components->twoColumnDetail('Fleet host', ($host['enabled'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Worker capacity', ((int) ($host['capacity_mib'] ?? 0)).' MiB');

        $smoke = data_get($host, 'proof.smoke_passed_at');
        $this->components->twoColumnDetail('Smoke test', $smoke ? 'passed '.$smoke : 'not run — php artisan dply:queue:fleet-smoke '.$server->name);

        $soak = data_get($host, 'proof.soak_started_at');
        if (! is_string($soak)) {
            return;
        }

        $since = Carbon::parse($soak);
        $days = (int) $since->diffInDays(now(), absolute: true);
        // Errored is start-failed or vanished — dply asked for a worker and the host did not keep it.
        $failures = ManagedQueueWorker::query()
            ->where('host_server_id', $server->id)
            ->where('state', ManagedQueueWorker::STATE_ERRORED)
            ->where('stopped_at', '>=', $since)
            ->count();

        $verdict = match (true) {
            $failures > 0 => 'failing',
            $days >= self::SOAK_DAYS => 'passed',
            default => 'running',
        };

        $this->components->twoColumnDetail(
            'Soak',
            sprintf('%s — day %d of %d, %d worker failure(s)', $verdict, min($days, self::SOAK_DAYS), self::SOAK_DAYS, $failures),
        );
    }
}
