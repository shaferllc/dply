<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Console\Command;

/**
 * Set / unset a single environment variable on a site.
 *
 *   dply:site:env-set <site> KEY=VALUE
 *   dply:site:env-set <site> KEY= --unset      # remove the variable
 *
 * Values are written into the encrypted env cache (`sites.env_file_content`).
 * Use `dply:site:env-push` (or the dashboard's Push button) afterwards to
 * write the resulting .env file to the server.
 *
 * Idempotent: re-running with the same KEY=VALUE updates the existing
 * line in place. Per-site uniqueness is by env_key.
 */
class SetSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-set
        {site : Site ID, slug, or name}
        {assignment : KEY=VALUE format (use empty value with --unset to remove)}
        {--unset : Remove the variable instead of setting it}';

    protected $description = 'Set or unset a single environment variable on a site.';

    public function handle(DotEnvFileParser $parser, DotEnvFileWriter $writer): int
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

        $unset = (bool) $this->option('unset');
        $map = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        $existed = array_key_exists($key, $map);

        if ($unset) {
            unset($map[$key]);
            $site->forceFill([
                'env_file_content' => $writer->render($map),
                'env_cache_origin' => 'local-edit',
            ])->save();

            $this->info($existed
                ? sprintf('Removed %s from %s.', $key, $site->name)
                : sprintf('%s was not set on %s.', $key, $site->name));

            return self::SUCCESS;
        }

        $map[$key] = $value;
        $site->forceFill([
            'env_file_content' => $writer->render($map),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $this->info(sprintf('Set %s on %s.', $key, $site->name));

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
