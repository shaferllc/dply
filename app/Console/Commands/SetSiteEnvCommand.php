<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Console\Command;

/**
 * Set / unset a single environment variable on a site.
 *
 *   dply:site:env-set <site> KEY=VALUE [--environment=production]
 *   dply:site:env-set <site> KEY= --unset      # remove the variable
 *
 * Useful for CI scripts and ad-hoc ops without going through the
 * dashboard. Values are stored encrypted by the model's cast.
 *
 * Idempotent: re-running with the same KEY=VALUE updates the
 * existing row in place rather than creating a duplicate. Per-site
 * uniqueness is by (site, environment, env_key).
 */
class SetSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-set
        {site : Site ID, slug, or name}
        {assignment : KEY=VALUE format (use empty value with --unset to remove)}
        {--environment=production : Environment scope}
        {--unset : Remove the variable instead of setting it}';

    protected $description = 'Set or unset a single environment variable on a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $assignment = (string) $this->argument('assignment');
        $eq = strpos($assignment, '=');
        if ($eq === false) {
            $this->error('Assignment must be KEY=VALUE (or KEY= with --unset).');

            return self::FAILURE;
        }
        $key = trim(substr($assignment, 0, $eq));
        $value = substr($assignment, $eq + 1);

        if ($key === '' || ! preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
            $this->error('KEY must match /^[A-Z_][A-Z0-9_]*$/i.');

            return self::FAILURE;
        }

        $environment = (string) ($this->option('environment') ?? 'production');
        $unset = (bool) $this->option('unset');

        if ($unset) {
            $deleted = SiteEnvironmentVariable::query()
                ->where('site_id', $site->id)
                ->where('environment', $environment)
                ->where('env_key', $key)
                ->delete();

            $this->info($deleted > 0
                ? sprintf('Removed %s from %s (%s).', $key, $site->name, $environment)
                : sprintf('%s was not set on %s (%s).', $key, $site->name, $environment));

            return self::SUCCESS;
        }

        SiteEnvironmentVariable::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'environment' => $environment,
                'env_key' => $key,
            ],
            ['env_value' => $value],
        );

        $this->info(sprintf(
            'Set %s on %s (%s).',
            $key,
            $site->name,
            $environment,
        ));

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
