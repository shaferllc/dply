<?php

declare(strict_types=1);

namespace App\Modules\Insights\Console;

use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchServerInsightsCommand extends Command
{
    protected $signature = 'dply:dispatch-server-insights';

    protected $description = 'Queue insight runs for ready servers with an IP address.';

    public function handle(): int
    {
        $count = 0;

        Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address', 'and')
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                RunServerInsightsJob::dispatch($id);
                $count++;
            });

        $this->components->info("Queued {$count} server insight run(s).");

        return self::SUCCESS;
    }
}
