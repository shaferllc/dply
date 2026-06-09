<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScanServerSshLoginsJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchSshLoginScansCommand extends Command
{
    protected $signature = 'dply:dispatch-ssh-login-scans';

    protected $description = 'Queue SSH login scans for servers with ssh_login notification subscribers.';

    public function handle(): int
    {
        $count = 0;

        ScanServerSshLoginsJob::eligibleServers()
            ->each(function (Server $server) use (&$count): void {
                ScanServerSshLoginsJob::dispatch((string) $server->id);
                $count++;
            });

        $this->components->info("Queued {$count} SSH login scan(s).");

        return self::SUCCESS;
    }
}
