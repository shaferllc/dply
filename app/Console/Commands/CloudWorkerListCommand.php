<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List background workers for a Cloud site — the CLI mirror of the
 * dashboard Workers section.
 *
 *   dply:cloud:worker:list --site=<id|slug|name> [--json]
 */
class CloudWorkerListCommand extends Command
{
    protected $signature = 'dply:cloud:worker:list
        {--site= : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List background workers for a Cloud site.';

    public function handle(): int
    {
        $needle = trim((string) $this->option('site'));
        if ($needle === '') {
            $this->error('--site=<id|slug|name> is required.');

            return self::FAILURE;
        }

        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $workers = CloudWorker::query()
            ->where('site_id', $site->id)
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();

        $rows = $workers->map(fn (CloudWorker $w): array => [
            'id' => $w->id,
            'type' => $w->type,
            'name' => $w->name,
            'command' => $w->effectiveCommand(),
            'count' => $w->effectiveInstanceCount(),
            'size' => $w->size,
            'status' => $w->status,
        ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site' => $site->id,
                'total' => count($rows),
                'workers' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line('<fg=gray>No workers configured for this site.</>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Workers</> for '.$site->name.' ('.count($rows).')');
        $this->newLine();

        $this->table(
            ['id', 'type', 'name', 'command', 'count', 'size', 'status'],
            array_map(fn (array $r): array => [
                $r['id'],
                $r['type'],
                $r['name'],
                $r['command'],
                (string) $r['count'],
                $r['size'],
                $r['status'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
