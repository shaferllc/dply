<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunServerReleaseHygieneScanJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchReleaseHygieneScansCommand extends Command
{
    protected $signature = 'dply:dispatch-release-hygiene-scans';

    protected $description = 'Queue release hygiene scans for servers with release_hygiene notification subscribers.';

    public function handle(): int
    {
        $count = 0;

        RunServerReleaseHygieneScanJob::eligibleServers()
            ->each(function (Server $server) use (&$count): void {
                RunServerReleaseHygieneScanJob::dispatch((string) $server->id);
                $count++;
            });

        $this->components->info("Queued {$count} release hygiene scan(s).");

        return self::SUCCESS;
    }
}
