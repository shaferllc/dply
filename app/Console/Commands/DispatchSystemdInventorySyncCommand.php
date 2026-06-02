<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchSystemdInventorySyncCommand extends Command
{
    protected $signature = 'dply:dispatch-systemd-inventory-sync';

    protected $description = 'Queue systemd inventory sync jobs for SSH-ready servers.';

    public function handle(): int
    {
        if (! (bool) config('server_services.systemd_inventory_schedule_enabled', true)) {
            $this->components->info('Systemd inventory scheduling is disabled.');

            return self::SUCCESS;
        }

        if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
            $this->components->info('Systemd inventory jobs are disabled.');

            return self::SUCCESS;
        }

        $count = 0;

        Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address', 'and')
            ->whereNotNull('ssh_private_key', 'and')
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                SyncServerSystemdServicesJob::dispatch($id);
                $count++;
            });

        $this->components->info("Queued {$count} systemd inventory sync job(s).");

        return self::SUCCESS;
    }
}
