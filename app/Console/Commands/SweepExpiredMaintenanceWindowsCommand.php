<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ServerMaintenanceWindow;
use Illuminate\Console\Command;

/**
 * Auto-clear visitor maintenance windows once their `until` timestamp passes.
 *
 * {@see ServerMaintenanceWindow::refreshExpired()} is otherwise only triggered
 * when an operator opens the Maintenance page, so a timed window left
 * unattended would keep sites suspended forever. This sweep makes timed
 * windows self-clear (resume sites + re-apply webserver config) without a
 * page visit.
 */
class SweepExpiredMaintenanceWindowsCommand extends Command
{
    protected $signature = 'dply:maintenance:sweep-expired';

    protected $description = 'Clear expired server maintenance windows and resume suspended sites';

    public function handle(ServerMaintenanceWindow $maintenance): int
    {
        $cleared = 0;

        Server::query()
            ->where('meta->maintenance->active', true)
            ->each(function (Server $server) use ($maintenance, &$cleared): void {
                if ($maintenance->refreshExpired($server)) {
                    $cleared++;
                }
            });

        if ($cleared > 0) {
            $this->info("Cleared {$cleared} expired maintenance window(s).");
        }

        return self::SUCCESS;
    }
}
