<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

/**
 * List servers with runtime + engine + site-count summary.
 *
 *   dply:server:list [--ready] [--limit=100] [--json]
 *
 * Counterpart to dply:site:list — gives ops a fleet-level view.
 * Each row shows id (last 8 of ULID), name, status, IP, the runtime
 * keys pinned in meta.runtime_defaults, the registered DB engines,
 * and the count of sites attached.
 */
class ListServersCommand extends Command
{
    protected $signature = 'dply:server:list
        {--ready : Only show servers in STATUS_READY}
        {--limit=100 : Max rows}
        {--json : Output as JSON}';

    protected $description = 'List servers with runtime / engine / site-count summary.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) ($this->option('limit') ?? 100)));
        $query = Server::query()
            ->withCount('sites')
            ->with('databaseEngines')
            ->orderBy('name');

        if ($this->option('ready')) {
            $query->where('status', Server::STATUS_READY);
        }

        $servers = $query->limit($limit)->get();

        if ($this->option('json')) {
            $this->line(json_encode([
                'count' => $servers->count(),
                'servers' => $servers->map(fn (Server $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'status' => $s->status,
                    'ip_address' => $s->ip_address,
                    'php_version' => is_string($s->meta['php_version'] ?? null) ? $s->meta['php_version'] : null,
                    'runtimes' => $s->installedRuntimeKeys(),
                    'engines' => $s->databaseEngines->map(fn ($e) => $e->engine.($e->is_default ? '*' : ''))->all(),
                    'site_count' => (int) ($s->sites_count ?? 0),
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($servers->isEmpty()) {
            $this->info('No servers found.');

            return self::SUCCESS;
        }

        $rows = $servers->map(function (Server $s) {
            $runtimes = $s->installedRuntimeKeys();
            $php = is_string($s->meta['php_version'] ?? null) ? $s->meta['php_version'] : null;
            $runtimeBlurb = implode(', ', array_filter([
                $php ? "php {$php}" : null,
                ...array_map(fn ($k) => $k, $runtimes),
            ]));
            $engines = $s->databaseEngines->map(fn ($e) => $e->engine.($e->is_default ? '*' : ''))->implode(', ');

            return [
                substr((string) $s->id, -8),
                $s->name,
                $s->status,
                $s->ip_address ?? '—',
                $runtimeBlurb !== '' ? $runtimeBlurb : '—',
                $engines !== '' ? $engines : '—',
                (string) ($s->sites_count ?? 0),
            ];
        })->all();

        $this->table(['id', 'name', 'status', 'ip', 'runtimes', 'engines', 'sites'], $rows);
        $this->newLine();
        $this->line('<fg=gray>* = default engine.</>');

        return self::SUCCESS;
    }
}
