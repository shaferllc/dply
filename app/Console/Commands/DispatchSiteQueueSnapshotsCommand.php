<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Models\SupervisorProgram;
use App\Support\Sites\QueueWorkerClassifier;
use Illuminate\Console\Command;

class DispatchSiteQueueSnapshotsCommand extends Command
{
    protected $signature = 'dply:dispatch-site-queue-snapshots';

    protected $description = 'Queue a per-server queue-depth snapshot for every server hosting a site with queue workers.';

    public function handle(): int
    {
        if (! config('dply.site_queue_snapshots_enabled', true)) {
            $this->components->info('Site queue snapshots are disabled.');

            return self::SUCCESS;
        }

        // Fan out per SERVER: the job snapshots every queue-bearing site on the
        // box in one SSH session, so cost scales with servers rather than sites.
        // The classifier runs here so a server whose only daemons are non-queue
        // never gets connected to at all.
        $serverIds = SupervisorProgram::query()
            ->whereNotNull('site_id')
            ->where('is_active', true)
            ->get(['server_id', 'command'])
            ->filter(fn (SupervisorProgram $program): bool => QueueWorkerClassifier::isQueueWorker($program->command))
            ->pluck('server_id')
            ->unique()
            ->values();

        foreach ($serverIds as $serverId) {
            CollectServerQueueSnapshotsJob::dispatch((string) $serverId);
        }

        $this->components->info(sprintf('Queued queue snapshots for %d server(s).', $serverIds->count()));

        return self::SUCCESS;
    }
}
