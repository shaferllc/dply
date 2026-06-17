<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Models\Server;
use App\Services\Servers\ServerMetricsGuestScript;
use Illuminate\Console\Command;

/**
 * Bulk-rolls the latest server-metrics-snapshot.py to every ready server in
 * the fleet. Dispatches one {@see UpgradeGuestMetricsScriptJob} per server;
 * each job deploys the script via SSH and updates the recorded SHA on
 * server.meta. Idempotent: jobs that find the script already current no-op.
 *
 * Use after editing resources/server-scripts/server-metrics-snapshot.py to
 * pick up new collectors (webserver_health, etc.) without waiting for the
 * scheduled SSH probe to discover the drift.
 *
 * Examples:
 *   php artisan dply:metrics-agent:push
 *   php artisan dply:metrics-agent:push --server=01krev...
 *   php artisan dply:metrics-agent:push --org=01krev... --dry-run
 */
class PushMetricsAgentCommand extends Command
{
    protected $signature = 'dply:metrics-agent:push
                            {--server= : Limit to one server (ULID)}
                            {--org= : Limit to one organization (ULID)}
                            {--dry-run : Print eligible servers without dispatching}';

    protected $description = 'Push the latest server-metrics-snapshot.py to all ready servers (or a filtered subset).';

    public function handle(ServerMetricsGuestScript $guest): int
    {
        $bundledSha = $guest->bundledSha256();
        $this->line('Bundled script SHA: '.$bundledSha);

        $query = Server::query()->where('status', Server::STATUS_READY);
        if ($serverId = $this->option('server')) {
            $query->where('id', $serverId);
        }
        if ($orgId = $this->option('org')) {
            $query->where('organization_id', $orgId);
        }

        $servers = $query->orderBy('name')->get(['id', 'name', 'meta']);
        if ($servers->isEmpty()) {
            $this->warn('No ready servers match the filters.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $alreadyCurrent = 0;
        $skipped = 0;

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run – no jobs dispatched.');
        }

        foreach ($servers as $server) {
            $meta = $server->meta;
            $current = (string) ($meta['monitoring_guest_script_sha256'] ?? $meta['monitoring_guest_script_sha'] ?? '');

            if ($current === $bundledSha) {
                $alreadyCurrent++;
                $this->line(sprintf('  [skip] %s — already current', $server->name));

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  [would push] %s (current=%s)', $server->name, $current !== '' ? substr($current, 0, 12) : 'unknown'));
                $skipped++;

                continue;
            }

            UpgradeGuestMetricsScriptJob::dispatch($server->id, $bundledSha);
            $dispatched++;
            $this->info(sprintf('  [push]   %s queued', $server->name));
        }

        $this->newLine();
        $this->line(sprintf(
            'Done. dispatched=%d already-current=%d eligible=%d',
            $dispatched,
            $alreadyCurrent,
            $servers->count(),
        ));

        if ($dryRun && $skipped > 0) {
            $this->line('Re-run without --dry-run to actually dispatch.');
        }

        return self::SUCCESS;
    }
}
