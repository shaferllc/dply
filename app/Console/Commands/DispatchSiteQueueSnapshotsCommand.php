<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Models\Site;
use App\Models\SiteProcess;
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
        // Stopped workers and paused queues included: a site whose only worker
        // was switched off still receives jobs, and used to go unwatched.
        $supervisorServerIds = SupervisorProgram::query()
            ->whereNotNull('site_id')
            ->get(['server_id', 'command'])
            ->filter(fn (SupervisorProgram $program): bool => QueueWorkerClassifier::isQueueWorker($program->command))
            ->pluck('server_id');

        // systemd units are how VM sites run workers until moved to Supervisor;
        // leaving them out meant those servers were never swept at all.
        $systemdServerIds = SiteProcess::query()
            ->where('type', '!=', SiteProcess::TYPE_WEB)
            ->with('site:id,server_id')
            ->get(['site_id', 'command'])
            ->filter(fn (SiteProcess $process): bool => QueueWorkerClassifier::isQueueWorker($process->command))
            ->map(fn (SiteProcess $process): ?string => $process->site?->server_id);

        $pausedServerIds = Site::query()->whereNotNull('meta->queue_paused')->pluck('server_id');

        $serverIds = $supervisorServerIds->concat($systemdServerIds)->concat($pausedServerIds)
            ->filter()
            ->unique()
            ->values();

        foreach ($serverIds as $serverId) {
            CollectServerQueueSnapshotsJob::dispatch((string) $serverId);
        }

        $this->components->info(sprintf('Queued queue snapshots for %d server(s).', $serverIds->count()));

        return self::SUCCESS;
    }
}
