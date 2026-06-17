<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudDatabase;
use Illuminate\Console\Command;

/**
 * List managed databases across the fleet — the CLI mirror of the
 * Cloud databases page.
 *
 *   dply:cloud:db:list [--json] [--engine=postgres]
 *                      [--status=active|provisioning|failed|deleting]
 */
class CloudDatabaseListCommand extends Command
{
    protected $signature = 'dply:cloud:db:list
        {--json : Output as JSON}
        {--engine= : Filter to a single engine (postgres, mysql, redis)}
        {--status= : Filter to a single status (provisioning, active, failed, deleting)}';

    protected $description = 'List managed databases across the fleet.';

    public function handle(): int
    {
        $query = CloudDatabase::query()
            ->with('organization:id,name')
            ->withCount('sites')
            ->orderBy('organization_id')
            ->orderBy('name');

        $engine = $this->option('engine');
        if (is_string($engine) && $engine !== '') {
            $engineKey = strtolower($engine);
            if (! in_array($engineKey, [
                CloudDatabase::ENGINE_POSTGRES,
                CloudDatabase::ENGINE_MYSQL,
                CloudDatabase::ENGINE_REDIS,
            ], true)) {
                $this->error('Unknown --engine. Use one of: postgres, mysql, redis');

                return self::FAILURE;
            }
            $query->where('engine', $engineKey);
        }

        $status = $this->option('status');
        if (is_string($status) && $status !== '') {
            $statusKey = strtolower($status);
            $statuses = [
                CloudDatabase::STATUS_PROVISIONING,
                CloudDatabase::STATUS_ACTIVE,
                CloudDatabase::STATUS_FAILED,
                CloudDatabase::STATUS_DELETING,
            ];
            if (! in_array($statusKey, $statuses, true)) {
                $this->error('Unknown --status. Use one of: '.implode(', ', $statuses));

                return self::FAILURE;
            }
            $query->where('status', $statusKey);
        }

        $rows = $query->get()->map(fn (CloudDatabase $db): array => [
            'id' => $db->id,
            'name' => $db->name,
            'organization' => $db->organization->name ?? '—',
            'engine' => $db->engine,
            'version' => $db->version,
            'size' => $db->size,
            'region' => $db->region,
            'status' => $db->status,
            'sites' => (int) $db->sites_count,
        ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'total' => count($rows),
                'databases' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No managed databases found.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Managed databases</> ('.count($rows).')');
        $this->newLine();

        $this->table(
            ['id', 'name', 'organization', 'engine', 'version', 'size', 'region', 'status', 'sites'],
            array_map(fn (array $r): array => [
                $r['id'],
                $r['name'],
                $r['organization'],
                $r['engine'],
                $r['version'] !== '' ? $r['version'] : '—',
                $r['size'],
                $r['region'] !== '' ? $r['region'] : '—',
                $r['status'],
                (string) $r['sites'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
