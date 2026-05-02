<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Console\Command;

/**
 * Bulk-delete every environment variable in a scope.
 *
 *   dply:site:env-clear <site> [--environment=production] --force
 *   dply:site:env-clear <site> --dry-run
 *
 * Requires --force to actually delete (a strict opt-in since this
 * is destructive). --dry-run reports the count without writing.
 *
 * Companion to env-import --replace for the case where you want to
 * fully drain the scope without immediately seeding from a file.
 * Useful when migrating envs across runtimes — tear down the old
 * config first, then import the new one.
 */
class ClearSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-clear
        {site : Site ID, slug, or name}
        {--environment=production : Environment scope to clear}
        {--force : Required to actually delete}
        {--dry-run : Report what would be deleted without writing}
        {--json : Output as JSON}';

    protected $description = 'Bulk-delete all environment variables in a scope.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $environment = (string) ($this->option('environment') ?? 'production');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $rows = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('environment', $environment)
            ->pluck('env_key')
            ->all();
        sort($rows);

        if (! $force && ! $dryRun) {
            $this->error('Refusing to clear without --force (or --dry-run to preview).');

            return self::FAILURE;
        }

        $deleted = 0;
        if ($force && ! $dryRun) {
            $deleted = SiteEnvironmentVariable::query()
                ->where('site_id', $site->id)
                ->where('environment', $environment)
                ->delete();
        }

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'environment' => $environment,
            'dry_run' => $dryRun,
            'count' => count($rows),
            'deleted' => $deleted,
            'keys' => $rows,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf(
                'Would delete %d variable(s) from %s (%s).',
                count($rows), $site->name, $environment,
            ));
        } else {
            $this->info(sprintf(
                'Deleted %d variable(s) from %s (%s).',
                $deleted, $site->name, $environment,
            ));
        }
        if ($rows !== []) {
            foreach ($rows as $k) {
                $this->line('  - '.$k);
            }
        }

        return self::SUCCESS;
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
