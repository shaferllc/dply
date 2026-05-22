<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Servers\MiseInstallScriptBuilder;
use Illuminate\Console\Command;

/**
 * Mutate a site's runtime configuration from the terminal.
 *
 *   dply:site:set-runtime <site>
 *     [--runtime=node] [--version=20.10.0] [--build=...] [--start=...]
 *     [--port=3000]    [--engine=postgres] [--unset-engine]
 *     [--dry-run]      [--json]
 *
 * Each option is independent: omit one and the corresponding column
 * is left untouched. --unset-engine clears the database_engine
 * column (useful for static sites that picked one up by accident).
 *
 * Validates --runtime against the catalog (PHP + mise-managed
 * runtimes + 'static'). Validates --port is a positive int.
 *
 * --dry-run reports the diff without writing. The non-dry path
 * always emits the diff so operators can see what changed.
 */
class SetSiteRuntimeCommand extends Command
{
    protected $signature = 'dply:site:set-runtime
        {site : Site ID, slug, or name}
        {--runtime= : Runtime key (php, node, python, ruby, go, static)}
        {--runtime-version= : Runtime version pin (e.g. 20.10.0, 3.12.1)}
        {--build= : Build command, e.g. "npm run build"}
        {--start= : Start command, e.g. "node server.js"}
        {--port= : Internal port the runtime listens on}
        {--engine= : Database engine (postgres, mysql, sqlite, ...)}
        {--unset-engine : Clear the database_engine column}
        {--dry-run : Report the proposed change without writing}
        {--json : Output the result as JSON}';

    protected $description = 'Update a site\'s runtime, version, build/start commands, internal port, or database engine.';

    private const ALLOWED_RUNTIMES = ['php', 'static'];

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $allowedRuntimes = array_merge(self::ALLOWED_RUNTIMES, MiseInstallScriptBuilder::SUPPORTED_RUNTIMES);
        $changes = [];

        $runtime = $this->option('runtime');
        if ($runtime !== null) {
            if (! in_array($runtime, $allowedRuntimes, true)) {
                $this->error(sprintf(
                    'Unknown runtime "%s". Allowed: %s',
                    $runtime,
                    implode(', ', $allowedRuntimes),
                ));

                return self::FAILURE;
            }
            $changes['runtime'] = $runtime;
        }

        $version = $this->option('runtime-version');
        if ($version !== null) {
            $changes['runtime_version'] = $version === '' ? null : $version;
        }

        $build = $this->option('build');
        if ($build !== null) {
            $changes['build_command'] = $build === '' ? null : $build;
        }

        $start = $this->option('start');
        if ($start !== null) {
            $changes['start_command'] = $start === '' ? null : $start;
        }

        $port = $this->option('port');
        if ($port !== null) {
            if (! ctype_digit((string) $port) || (int) $port <= 0 || (int) $port > 65535) {
                $this->error("Invalid port: {$port} (must be 1-65535).");

                return self::FAILURE;
            }
            $changes['internal_port'] = (int) $port;
        }

        $engine = $this->option('engine');
        $unsetEngine = (bool) $this->option('unset-engine');
        if ($engine !== null && $unsetEngine) {
            $this->error('--engine and --unset-engine are mutually exclusive.');

            return self::FAILURE;
        }
        if ($unsetEngine) {
            $changes['database_engine'] = null;
        } elseif ($engine !== null) {
            $changes['database_engine'] = $engine;
        }

        if ($changes === []) {
            $this->error('No changes requested. Pass at least one --runtime/--version/--build/--start/--port/--engine.');

            return self::FAILURE;
        }

        $diff = [];
        foreach ($changes as $col => $newValue) {
            $diff[$col] = ['from' => $site->getAttribute($col), 'to' => $newValue];
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun) {
            $site->fill($changes)->save();
        }

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'dry_run' => $dryRun,
            'changes' => $diff,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info(sprintf('%s %s:', $verb, $site->name));
        foreach ($diff as $col => $change) {
            $this->line(sprintf(
                '  %-18s %s → %s',
                $col,
                $this->display($change['from']),
                $this->display($change['to']),
            ));
        }

        return self::SUCCESS;
    }

    private function display(mixed $v): string
    {
        if ($v === null) {
            return '<fg=gray>null</>';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
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
