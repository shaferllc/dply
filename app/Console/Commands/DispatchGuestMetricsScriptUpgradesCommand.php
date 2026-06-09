<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestScript;
use Illuminate\Console\Command;

class DispatchGuestMetricsScriptUpgradesCommand extends Command
{
    protected $signature = 'dply:dispatch-guest-metrics-script-upgrades';

    protected $description = 'Queue guest metrics script upgrades when the bundled SHA differs from deployed.';

    public function handle(ServerMetricsGuestScript $guestScript): int
    {
        if (! (bool) config('server_metrics.guest_script.scheduled_upgrades_enabled', true)) {
            $this->components->info('Scheduled guest metrics script upgrades are disabled.');

            return self::SUCCESS;
        }

        $bundledSha = $guestScript->bundledSha256();
        if ($bundledSha === '') {
            $this->components->warn('Bundled guest metrics script SHA is empty; skipping.');

            return self::SUCCESS;
        }

        $count = 0;

        Server::query()
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ip_address', 'and')
            ->whereNotNull('ssh_private_key', 'and')
            ->each(function (Server $server) use ($bundledSha, &$count): void {
                $deployedSha = (string) ($server->meta['monitoring_guest_script_sha'] ?? $server->meta['monitoring_guest_script_sha256'] ?? '');
                if ($deployedSha === $bundledSha) {
                    return;
                }

                UpgradeGuestMetricsScriptJob::dispatch($server->id, $bundledSha);
                $count++;
            });

        $this->components->info("Queued {$count} guest metrics script upgrade(s).");

        return self::SUCCESS;
    }
}
