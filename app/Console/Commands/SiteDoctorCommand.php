<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;

/**
 * Per-site diagnostic snapshot.
 *
 *   dply:site:doctor <site> [--json]
 *
 * Reports:
 *   - Runtime + version + framework + build/start/internal_port.
 *   - Database engine pinned on the site, and whether the server
 *     has it registered (drift surfaces when the operator picks an
 *     engine the server doesn't run).
 *   - Configured processes (active count + scale tally) and any
 *     processes flagged inactive.
 *   - Latest deployment summary: status, started_at, phases recorded.
 *   - Env var count per environment (production + staging).
 *   - Drift warnings collected from the above checks.
 *
 * Read-only — no SSH, no writes.
 */
class SiteDoctorCommand extends Command
{
    protected $signature = 'dply:site:doctor
        {site : Site ID, slug, or name}
        {--json : Output the diagnostic as JSON}';

    protected $description = 'Diagnostic: runtime, processes, deploy status, and drift for a single site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $report = $this->compileReport($site);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($site, $report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(Site $site): array
    {
        $server = $site->server;
        $drift = [];

        $engine = $site->databaseEngine();
        $engineRegistered = null;
        if ($engine !== null && $server !== null) {
            $engineRegistered = $server->databaseEngines()
                ->where('engine', $engine)
                ->exists();
            if (! $engineRegistered) {
                $drift[] = sprintf(
                    'Site requests database engine "%s" but server "%s" has not registered it.',
                    $engine,
                    $server->name,
                );
            }
        }

        $processes = $site->processes()->orderBy('type')->orderBy('name')->get();
        $activeCount = $processes->where('is_active', true)->count();
        $totalScale = (int) $processes->where('is_active', true)->sum('scale');

        $latest = $site->latestDeployment();
        $latestSummary = null;
        if ($latest !== null) {
            $phases = $latest->phase_results ?? [];
            $latestSummary = [
                'id' => $latest->id,
                'status' => $latest->status,
                'started_at' => $latest->started_at?->toIso8601String(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
                'phases_recorded' => array_keys($phases),
                'phases_ok' => $latest->phasesAllOk(),
            ];
        }

        $envParsed = app(DotEnvFileParser::class)
            ->parse((string) ($site->env_file_content ?? ''));
        $envCounts = [
            'cached_keys' => count($envParsed['variables']),
            'parse_errors' => count($envParsed['errors']),
        ];

        // Surface "env file lives inside the docroot" as drift. The webserver
        // deny rule blocks /.env over HTTP, but defense-in-depth prefers the
        // file outside the docroot entirely. We only flag VM-style hosts —
        // container/serverless runtimes don't have a host file and the cache
        // is canonical.
        $envInDocroot = false;
        if ($server?->hostCapabilities()->supportsEnvPushToHost()) {
            $envPath = $site->effectiveEnvFilePath();
            $docroot = rtrim((string) $site->effectiveDocumentRoot(), '/');
            $envInDocroot = $docroot !== '' && str_starts_with($envPath, $docroot.'/');
            if ($envInDocroot) {
                $drift[] = sprintf(
                    'Env file is inside the docroot (%s). Move it outside via dply:site:env-relocate %s, or set a custom env_file_path on the Environment page.',
                    $envPath,
                    $site->slug,
                );
            }
        }
        $envLocation = [
            'path' => $site->effectiveEnvFilePath(),
            'in_docroot' => $envInDocroot,
            'overridden' => trim((string) $site->env_file_path) !== '',
        ];

        $domains = $site->domains()
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'is_primary'])
            ->map(fn ($d) => [
                'hostname' => $d->hostname,
                'is_primary' => (bool) $d->is_primary,
                'url' => 'https://'.$d->hostname,
            ])
            ->all();
        if ($domains === []) {
            $drift[] = 'Site has no domains configured. Add one with dply:site:domain-add.';
        }

        return [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'server_id' => $server?->id,
            'server_name' => $server?->name,
            'runtime' => [
                'key' => $site->runtimeKey(),
                'version' => $site->runtimeVersion(),
                'framework' => $site->resolvedRuntimeAppDetection()['framework'] ?? null,
                'build_command' => $site->build_command,
                'start_command' => $site->start_command,
                'internal_port' => $site->internal_port,
            ],
            'database' => [
                'engine' => $engine,
                'server_has_engine' => $engineRegistered,
            ],
            'processes' => [
                'total' => $processes->count(),
                'active' => $activeCount,
                'inactive' => $processes->count() - $activeCount,
                'total_scale' => $totalScale,
                'list' => $processes->map(fn ($p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'name' => $p->name,
                    'scale' => $p->scale,
                    'is_active' => (bool) $p->is_active,
                ])->all(),
            ],
            'latest_deployment' => $latestSummary,
            'env_var_counts' => $envCounts,
            'env_location' => $envLocation,
            'domains' => $domains,
            'drift' => $drift,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function renderHuman(Site $site, array $r): void
    {
        $this->newLine();
        $this->line("<fg=cyan>Site doctor for</> <fg=white;options=bold>{$site->name}</> <fg=gray>({$site->id})</>");
        if ($r['server_name']) {
            $this->line("  on server <fg=cyan>{$r['server_name']}</>");
        }
        $this->newLine();

        $this->line('<fg=cyan>Runtime</>');
        $rt = $r['runtime'];
        $this->line(sprintf('  %-14s %s', 'key', $rt['key'] ?? '<fg=yellow>unset</>'));
        $this->line(sprintf('  %-14s %s', 'version', $rt['version'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'framework', $rt['framework'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'build', $rt['build_command'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'start', $rt['start_command'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'internal_port', $rt['internal_port'] ?? '—'));

        $this->newLine();
        $this->line('<fg=cyan>Database</>');
        $db = $r['database'];
        if ($db['engine'] === null) {
            $this->line('  <fg=gray>No engine pinned.</>');
        } else {
            $reg = $db['server_has_engine'] === true ? '<fg=green>registered</>' : '<fg=red>NOT registered</>';
            $this->line(sprintf('  %-14s %s  %s', 'engine', $db['engine'], $reg));
        }

        $this->newLine();
        $this->line('<fg=cyan>Processes</>');
        $p = $r['processes'];
        $this->line(sprintf(
            '  %d total / %d active / %d inactive / scale=%d',
            $p['total'], $p['active'], $p['inactive'], $p['total_scale'],
        ));
        foreach ($p['list'] as $proc) {
            $marker = $proc['is_active'] ? '<fg=green>●</>' : '<fg=gray>○</>';
            $this->line(sprintf('    %s %s/%s  ×%d', $marker, $proc['type'], $proc['name'], $proc['scale']));
        }

        $this->newLine();
        $this->line('<fg=cyan>Domains</>');
        if ($r['domains'] === []) {
            $this->line('  <fg=yellow>None configured.</>');
        } else {
            foreach ($r['domains'] as $d) {
                $marker = $d['is_primary'] ? '<fg=green>★</>' : ' ';
                $this->line(sprintf('  %s %s', $marker, $d['hostname']));
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>Latest deployment</>');
        $d = $r['latest_deployment'];
        if ($d === null) {
            $this->line('  <fg=gray>No deployments yet.</>');
        } else {
            $this->line(sprintf('  %-14s %s', 'id', $d['id']));
            $this->line(sprintf('  %-14s %s', 'status', $d['status']));
            $this->line(sprintf('  %-14s %s', 'started', $d['started_at'] ?? '—'));
            $this->line(sprintf('  %-14s %s', 'phases', implode(', ', $d['phases_recorded']) ?: '—'));
        }

        $this->newLine();
        $this->line('<fg=cyan>Environment variables</>');
        foreach ($r['env_var_counts'] as $env => $count) {
            $this->line(sprintf('  %-14s %d', $env, $count));
        }

        $this->newLine();
        if ($r['drift'] === []) {
            $this->info('No drift detected.');
        } else {
            $this->warn('Drift:');
            foreach ($r['drift'] as $d) {
                $this->line('  '.$d);
            }
        }
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
