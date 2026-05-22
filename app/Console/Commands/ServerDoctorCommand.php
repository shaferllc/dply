<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Diagnostic snapshot for a server: installed runtimes, registered
 * database engines, and any drift between what's recorded and what
 * sites are actually using.
 *
 *   dply:server:doctor <server> [--json]
 *
 * Reports (in human mode):
 *   - Runtime defaults from meta.runtime_defaults — pinned versions
 *     mise will install at deploy time.
 *   - Database engines from server_database_engines, marking which
 *     is_default and any "orphan" engines no site targets.
 *   - Sites pinned to engines NOT registered on the server (data
 *     drift the operator should resolve before the next deploy).
 *   - Sites whose runtime isn't in meta.runtime_defaults — fine
 *     while mise can install on demand, but worth knowing for
 *     "why is this slower than expected" investigation.
 *
 * Read-only — no SSH, no writes. Pure data inspection.
 */
class ServerDoctorCommand extends Command
{
    protected $signature = 'dply:server:doctor
        {server : Server ID, name, or IP}
        {--json : Output the diagnostic as JSON}';

    protected $description = 'Diagnostic: list runtimes, engines, and any drift between server data and site usage.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error('Server not found: '.$needle);

            return self::FAILURE;
        }

        $report = $this->compileReport($server);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($server, $report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(Server $server): array
    {
        $runtimeDefaults = is_array($server->meta['runtime_defaults'] ?? null)
            ? $server->meta['runtime_defaults']
            : [];

        $engines = $server->databaseEngines()->orderBy('engine')->get()->map(fn ($e) => [
            'engine' => $e->engine,
            'version' => $e->version,
            'is_default' => (bool) $e->is_default,
        ])->all();
        $engineKeys = array_column($engines, 'engine');

        // Sites on this server whose database_engine is set to something
        // not in the engines list — drift to surface.
        $sitesWithMissingEngines = Site::query()
            ->where('server_id', $server->id)
            ->whereNotNull('database_engine')
            ->whereNotIn('database_engine', $engineKeys)
            ->get(['id', 'name', 'database_engine'])
            ->map(fn (Site $s) => [
                'site_id' => $s->id,
                'site_name' => $s->name,
                'requested_engine' => $s->database_engine,
            ])
            ->all();

        // Sites whose runtime isn't pre-pinned in meta.runtime_defaults.
        // For PHP this is fine (ondrej/php apt path), so excluded.
        $runtimeKeys = array_keys($runtimeDefaults);
        $sitesNeedingRuntimeInstall = Site::query()
            ->where('server_id', $server->id)
            ->whereNotIn('runtime', array_merge($runtimeKeys, ['php', 'static']))
            ->whereNotNull('runtime')
            ->get(['id', 'name', 'runtime', 'runtime_version'])
            ->map(fn (Site $s) => [
                'site_id' => $s->id,
                'site_name' => $s->name,
                'runtime' => $s->runtime,
                'runtime_version' => $s->runtime_version,
            ])
            ->all();

        return [
            'server_id' => $server->id,
            'server_name' => $server->name,
            'php_version' => is_string($server->meta['php_version'] ?? null)
                ? $server->meta['php_version']
                : null,
            'runtime_defaults' => $runtimeDefaults,
            'engines' => $engines,
            'drift' => [
                'sites_with_unregistered_engine' => $sitesWithMissingEngines,
                'sites_needing_runtime_install' => $sitesNeedingRuntimeInstall,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(Server $server, array $report): void
    {
        $this->newLine();
        $this->line("<fg=cyan>Server doctor for</> <fg=white;options=bold>{$server->name}</> <fg=gray>({$server->id})</>");
        $this->newLine();

        $this->line('<fg=cyan>Runtime defaults</>');
        if ($report['php_version']) {
            $this->line('  php       '.$report['php_version'].'  <fg=gray>(ondrej/php apt — not mise)</>');
        }
        if ($report['runtime_defaults'] === []) {
            $this->line('  <fg=yellow>No mise-managed runtimes pinned.</>');
        } else {
            foreach ($report['runtime_defaults'] as $runtime => $version) {
                $this->line(sprintf('  %-9s %s', $runtime, $version));
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>Database engines</>');
        if ($report['engines'] === []) {
            $this->line('  <fg=yellow>No engines registered.</>');
        } else {
            foreach ($report['engines'] as $engine) {
                $marker = $engine['is_default'] ? ' <fg=green>(default)</>' : '';
                $version = $engine['version'] ?? '—';
                $this->line(sprintf('  %-12s %s%s', $engine['engine'], $version, $marker));
            }
        }

        $drift = $report['drift'];
        $this->newLine();
        $this->line('<fg=cyan>Drift</>');
        if ($drift['sites_with_unregistered_engine'] === [] && $drift['sites_needing_runtime_install'] === []) {
            $this->info('  No drift detected.');

            return;
        }

        if ($drift['sites_with_unregistered_engine'] !== []) {
            $this->warn('  Sites pinned to engines NOT registered on this server:');
            foreach ($drift['sites_with_unregistered_engine'] as $row) {
                $this->line(sprintf('    %s → %s', $row['site_name'], $row['requested_engine']));
            }
        }

        if ($drift['sites_needing_runtime_install'] !== []) {
            $this->warn('  Sites with non-pinned runtimes (mise installs on demand):');
            foreach ($drift['sites_needing_runtime_install'] as $row) {
                $version = $row['runtime_version'] ? '@'.$row['runtime_version'] : '';
                $this->line(sprintf('    %s → %s%s', $row['site_name'], $row['runtime'], $version));
            }
        }
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
