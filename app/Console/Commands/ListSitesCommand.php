<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List sites — optionally filtered to one server or one runtime.
 *
 *   dply:site:list [--server=] [--runtime=] [--limit=50] [--json]
 *
 * Output mirrors the dashboard site list at the CLI: each row shows
 * id (last 8 of ULID), name, slug, runtime/version, internal port,
 * and status. Useful for ops review across many sites without
 * clicking through the dashboard.
 */
class ListSitesCommand extends Command
{
    protected $signature = 'dply:site:list
        {--server= : Filter to one server (ID, name, or IP)}
        {--runtime= : Filter to one runtime key (php, node, python, ruby, go, static)}
        {--limit=50 : Max rows}
        {--json : Output as JSON}';

    protected $description = 'List sites, optionally filtered by server or runtime.';

    public function handle(): int
    {
        $serverNeedle = (string) ($this->option('server') ?? '');
        $runtimeFilter = (string) ($this->option('runtime') ?? '');
        $limit = max(1, min(500, (int) ($this->option('limit') ?? 50)));

        $query = Site::query()->with('server')->orderBy('name');

        if ($serverNeedle !== '') {
            $server = Server::query()
                ->where('id', $serverNeedle)
                ->orWhere('name', $serverNeedle)
                ->orWhere('ip_address', $serverNeedle)
                ->first();
            if ($server === null) {
                $this->error('Server not found: '.$serverNeedle);

                return self::FAILURE;
            }
            $query->where('server_id', $server->id);
        }

        if ($runtimeFilter !== '') {
            $query->where('runtime', $runtimeFilter);
        }

        $sites = $query->limit($limit)->get();

        if ($this->option('json')) {
            $this->line(json_encode([
                'count' => $sites->count(),
                'sites' => $sites->map(fn (Site $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'server_id' => $s->server_id,
                    'server_name' => $s->server?->name,
                    'runtime' => $s->runtimeKey(),
                    'runtime_version' => $s->runtimeVersion(),
                    'internal_port' => $s->internal_port,
                    'database_engine' => $s->databaseEngine(),
                    'status' => $s->status,
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($sites->isEmpty()) {
            $this->info('No sites match the filter.');

            return self::SUCCESS;
        }

        $rows = $sites->map(fn (Site $s) => [
            substr((string) $s->id, -8),
            $s->name,
            $s->server->name ?? '—',
            $s->runtimeKey().($s->runtimeVersion() ? ' '.$s->runtimeVersion() : ''),
            $s->internal_port !== '' ? $s->internal_port : '—',
            $s->databaseEngine() ?? '—',
            $s->status,
        ])->all();

        $this->table(['id', 'name', 'server', 'runtime', 'port', 'engine', 'status'], $rows);

        return self::SUCCESS;
    }
}
