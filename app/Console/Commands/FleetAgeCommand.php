<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

/**
 * List servers by age from their created_at timestamp.
 *
 *   dply:fleet:age
 *   dply:fleet:age --older-than=365     # at least N days old
 *   dply:fleet:age --younger-than=30    # at most N days old
 *   dply:fleet:age --json
 *
 * Useful for capacity planning ("which servers do we have?"),
 * refresh sweeps ("which servers are overdue for OS updates?"),
 * and inventory dumps. Sorted oldest-first by default.
 *
 * "Age" is from server.created_at — when the dply DB row was
 * created, which generally matches when provisioning started. It
 * does NOT mean uptime; for that, SSH the box.
 */
class FleetAgeCommand extends Command
{
    protected $signature = 'dply:fleet:age
        {--older-than= : Only servers at least N days old}
        {--younger-than= : Only servers at most N days old}
        {--json : Output as JSON}';

    protected $description = 'List servers by age (from created_at).';

    public function handle(): int
    {
        $minAge = $this->option('older-than') !== null
            ? max(0, (int) $this->option('older-than'))
            : null;
        $maxAge = $this->option('younger-than') !== null
            ? max(0, (int) $this->option('younger-than'))
            : null;

        $servers = Server::query()
            ->orderBy('created_at')
            ->get(['id', 'name', 'ip_address', 'created_at', 'status']);

        $rows = [];
        foreach ($servers as $server) {
            $ageDays = (int) round($server->created_at->diffInDays(now()));
            if ($minAge !== null && $ageDays < $minAge) {
                continue;
            }
            if ($maxAge !== null && $ageDays > $maxAge) {
                continue;
            }
            $rows[] = [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'ip_address' => $server->ip_address,
                'status' => $server->status,
                'created_at' => $server->created_at->toIso8601String(),
                'age_days' => $ageDays,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'older_than_days' => $minAge,
                'younger_than_days' => $maxAge,
                'count' => count($rows),
                'servers' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No servers match the age filter.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d server(s):', count($rows)));
        $this->newLine();
        $this->table(
            ['server', 'ip', 'status', 'created', 'age'],
            array_map(fn (array $r) => [
                $r['server_name'],
                $r['ip_address'],
                $r['status'],
                substr($r['created_at'], 0, 10),
                $r['age_days'].'d',
            ], $rows),
        );

        return self::SUCCESS;
    }
}
