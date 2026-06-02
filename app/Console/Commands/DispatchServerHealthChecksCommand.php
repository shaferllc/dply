<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckServerHealthJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchServerHealthChecksCommand extends Command
{
    protected $signature = 'dply:dispatch-server-health-checks';

    protected $description = 'Queue health probes for ready servers with an IP address.';

    public function handle(): int
    {
        $count = 0;

        Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address', 'and')
            ->each(function (Server $server) use (&$count): void {
                CheckServerHealthJob::dispatch($server);
                $count++;
            });

        $this->components->info("Queued {$count} server health check(s).");

        return self::SUCCESS;
    }
}
