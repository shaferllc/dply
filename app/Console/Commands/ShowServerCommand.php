<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Comprehensive single-server profile.
 *
 *   dply:server:show <server>
 *   dply:server:show <server> --json
 *
 * Reports identity (id, name, IP, status, age), installed runtimes
 * (PHP via apt + mise-managed), registered database engines, and
 * the sites this server hosts (id, slug, runtime, primary domain,
 * latest deploy status).
 *
 * Complements:
 *   - dply:server:list (every server, terse one-line each)
 *   - dply:server:doctor (drift-focused; flags inconsistencies)
 *   - dply:server:show  (this — profile view of one server)
 */
class ShowServerCommand extends Command
{
    protected $signature = 'dply:server:show
        {server : Server ID, name, or IP}
        {--json : Output as JSON}';

    protected $description = 'Comprehensive profile of a single server.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error("Server not found: {$needle}");

            return self::FAILURE;
        }

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'runtime', 'runtime_version', 'status'])
            ->map(function (Site $site) {
                $primary = $site->domains()
                    ->orderByDesc('is_primary')
                    ->orderBy('hostname')
                    ->first(['hostname']);

                $latestDeploy = $site->latestDeployment();

                return [
                    'id' => $site->id,
                    'slug' => $site->slug,
                    'name' => $site->name,
                    'runtime' => $site->runtime,
                    'runtime_version' => $site->runtime_version,
                    'status' => $site->status,
                    'primary_hostname' => $primary?->hostname,
                    'last_deploy_status' => $latestDeploy?->status,
                    'last_deploy_at' => $latestDeploy?->finished_at?->toIso8601String(),
                ];
            })
            ->all();

        $engines = $server->databaseEngines()->orderBy('engine')->get(['engine', 'version', 'is_default'])
            ->map(fn ($e) => [
                'engine' => $e->engine,
                'version' => $e->version,
                'is_default' => (bool) $e->is_default,
            ])
            ->all();

        $runtimeDefaults = is_array($server->meta['runtime_defaults'] ?? null)
            ? $server->meta['runtime_defaults']
            : [];

        $report = [
            'id' => $server->id,
            'name' => $server->name,
            'ip_address' => $server->ip_address,
            'status' => $server->status,
            'created_at' => $server->created_at?->toIso8601String(),
            'age_days' => $server->created_at !== null
                ? (int) round($server->created_at->diffInDays(now()))
                : null,
            'php_version' => is_string($server->meta['php_version'] ?? null)
                ? $server->meta['php_version']
                : null,
            'runtime_defaults' => $runtimeDefaults,
            'database_engines' => $engines,
            'site_count' => count($sites),
            'sites' => $sites,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function renderHuman(array $r): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>Server</> <fg=white;options=bold>%s</> <fg=gray>(%s)</>',
            $r['name'],
            $r['id'],
        ));
        $this->line(sprintf(
            '  ip:        %s   status: %s   age: %s',
            $r['ip_address'] ?? '—',
            $r['status'] ?? '—',
            $r['age_days'] !== null ? $r['age_days'].'d' : '—',
        ));

        $this->newLine();
        $this->line('<fg=cyan>Runtimes</>');
        if ($r['php_version']) {
            $this->line('  php       '.$r['php_version'].' <fg=gray>(ondrej/php apt)</>');
        }
        foreach ($r['runtime_defaults'] as $key => $version) {
            $this->line(sprintf('  %-9s %s <fg=gray>(mise)</>', $key, $version));
        }
        if (! $r['php_version'] && $r['runtime_defaults'] === []) {
            $this->line('  <fg=yellow>None pinned.</>');
        }

        $this->newLine();
        $this->line('<fg=cyan>Database engines</>');
        if ($r['database_engines'] === []) {
            $this->line('  <fg=yellow>None registered.</>');
        } else {
            foreach ($r['database_engines'] as $e) {
                $marker = $e['is_default'] ? ' <fg=green>(default)</>' : '';
                $this->line(sprintf('  %-12s %s%s', $e['engine'], $e['version'] ?? '—', $marker));
            }
        }

        $this->newLine();
        $this->line(sprintf('<fg=cyan>Sites</> <fg=gray>(%d)</>', $r['site_count']));
        if ($r['sites'] === []) {
            $this->line('  <fg=gray>No sites hosted yet.</>');

            return;
        }
        $this->table(
            ['site', 'runtime', 'primary domain', 'last deploy'],
            array_map(fn (array $s) => [
                $s['name'],
                $s['runtime'].($s['runtime_version'] ? ' '.$s['runtime_version'] : ''),
                $s['primary_hostname'] ?? '—',
                $s['last_deploy_status'] ?? '<fg=gray>never</>',
            ], $r['sites']),
        );
    }

    private function resolveServer(string $needle): ?Server
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('ip_address', $needle)
            ->first();
    }
}
