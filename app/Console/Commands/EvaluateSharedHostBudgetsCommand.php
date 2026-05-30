<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Support\Servers\SharedHostBudgetMonitor;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

class EvaluateSharedHostBudgetsCommand extends Command
{
    protected $signature = 'dply:shared-host:evaluate-budgets {--server= : Limit to one server ULID}';

    protected $description = 'Evaluate shared-host soft budgets and send subscribed alerts';

    public function handle(SharedHostBudgetMonitor $monitor): int
    {
        if (! config('features.workspace.shared_host', true)) {
            $this->components->info('Shared Host Radar is disabled (workspace.shared_host).');

            return self::SUCCESS;
        }

        $query = Server::query()
            ->where('status', Server::STATUS_READY)
            ->has('sites', '>=', 2);

        if ($serverId = $this->option('server')) {
            $query->whereKey($serverId);
        }

        $count = 0;
        $query->each(function (Server $server) use ($monitor, &$count): void {
            if (! Feature::for($server->organization)->active('workspace.shared_host')) {
                return;
            }

            $monitor->evaluate($server);
            $count++;
        });

        $this->components->info("Evaluated shared-host budgets on {$count} server(s).");

        return self::SUCCESS;
    }
}
