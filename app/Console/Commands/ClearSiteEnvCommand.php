<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;

/**
 * Wipe every environment variable in a site's encrypted env cache.
 *
 *   dply:site:env-clear <site> --force
 *   dply:site:env-clear <site> --dry-run
 *
 * Requires --force to actually delete (a strict opt-in since this is
 * destructive). --dry-run reports the count without writing.
 *
 * Companion to env-import --replace for the case where you want to
 * fully drain the site without immediately seeding from a file.
 */
class ClearSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-clear
        {site : Site ID, slug, or name}
        {--force : Required to actually delete}
        {--dry-run : Report what would be deleted without writing}
        {--json : Output as JSON}';

    protected $description = 'Wipe all environment variables from a site\'s env cache.';

    public function handle(DotEnvFileParser $parser): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $existing = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        $keys = array_keys($existing);
        sort($keys);

        if (! $force && ! $dryRun) {
            $this->error('Refusing to clear without --force (or --dry-run to preview).');

            return self::FAILURE;
        }

        $deleted = 0;
        if ($force && ! $dryRun) {
            $deleted = count($keys);
            $site->forceFill([
                'env_file_content' => '',
                'env_cache_origin' => 'local-edit',
            ])->save();
        }

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'dry_run' => $dryRun,
            'count' => count($keys),
            'deleted' => $deleted,
            'keys' => $keys,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d variable(s) from %s.', count($keys), $site->name));
        } else {
            $this->info(sprintf('Deleted %d variable(s) from %s.', $deleted, $site->name));
        }
        if ($keys !== []) {
            foreach ($keys as $k) {
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
