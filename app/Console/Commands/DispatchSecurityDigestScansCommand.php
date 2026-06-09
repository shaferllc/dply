<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunServerSecurityDigestScanJob;
use App\Models\Server;
use Illuminate\Console\Command;

class DispatchSecurityDigestScansCommand extends Command
{
    protected $signature = 'dply:dispatch-security-digest-scans';

    protected $description = 'Queue security digest scans for servers with security_digest notification subscribers.';

    public function handle(): int
    {
        $count = 0;

        RunServerSecurityDigestScanJob::eligibleServers()
            ->each(function (Server $server) use (&$count): void {
                RunServerSecurityDigestScanJob::dispatch((string) $server->id);
                $count++;
            });

        $this->components->info("Queued {$count} security digest scan(s).");

        return self::SUCCESS;
    }
}
