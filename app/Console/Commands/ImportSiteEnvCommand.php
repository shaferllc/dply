<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Console\Command;

/**
 * Bulk import env vars from a .env file into a site's encrypted cache.
 *
 *   dply:site:env-import <site> --file=path/to/.env
 *                               [--replace] [--dry-run] [--json]
 *
 * Default mode merges: existing keys not in the file are kept; keys in
 * the file overwrite. --replace drops the cache and writes only the
 * file's contents — true sync.
 *
 * --dry-run parses + validates without touching the cache so you can
 * vet a file before committing. Errors (invalid lines) are reported
 * either way; presence of errors does NOT abort writes by default.
 */
class ImportSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-import
        {site : Site ID, slug, or name}
        {--file= : Path to a .env file to import}
        {--replace : Drop existing env cache before importing}
        {--dry-run : Parse and report without writing the cache}
        {--json : Output the import summary as JSON}';

    protected $description = 'Bulk import environment variables from a .env file into a site\'s env cache.';

    public function handle(DotEnvFileParser $parser, DotEnvFileWriter $writer): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $file = (string) ($this->option('file') ?? '');
        if ($file === '') {
            $this->error('--file is required.');

            return self::FAILURE;
        }
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or unreadable: {$file}");

            return self::FAILURE;
        }

        $replace = (bool) $this->option('replace');
        $dryRun = (bool) $this->option('dry-run');

        $contents = (string) file_get_contents($file);
        $parsed = $parser->parse($contents);
        $variables = $parsed['variables'];
        $errors = $parsed['errors'];

        $existing = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];

        $created = array_values(array_diff(array_keys($variables), array_keys($existing)));
        $updated = array_values(array_intersect(array_keys($variables), array_keys($existing)));
        $removed = $replace
            ? array_values(array_diff(array_keys($existing), array_keys($variables)))
            : [];

        $summary = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'mode' => $replace ? 'replace' : 'merge',
            'dry_run' => $dryRun,
            'parsed_count' => count($variables),
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'errors' => $errors,
        ];

        if (! $dryRun) {
            $merged = $replace ? $variables : array_merge($existing, $variables);
            $site->forceFill([
                'env_file_content' => $writer->render($merged),
                'env_cache_origin' => 'local-edit',
            ])->save();
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($summary);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function renderHuman(array $s): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>%s import</> for <fg=white;options=bold>%s</> — parsed %d variable(s)',
            $s['dry_run'] ? 'Dry-run' : ucfirst((string) $s['mode']),
            $s['site_name'],
            $s['parsed_count'],
        ));

        $this->line(sprintf('  <fg=green>created</>: %d', count($s['created'])));
        $this->line(sprintf('  <fg=yellow>updated</>: %d', count($s['updated'])));
        if ($s['mode'] === 'replace') {
            $this->line(sprintf('  <fg=red>removed</>: %d', count($s['removed'])));
        }

        if ($s['errors'] !== []) {
            $this->newLine();
            $this->warn('  Parse errors:');
            foreach ($s['errors'] as $err) {
                $this->line('    '.$err);
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
