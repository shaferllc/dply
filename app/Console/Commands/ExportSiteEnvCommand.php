<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use Illuminate\Console\Command;

/**
 * Export a site's env cache to .env file format.
 *
 *   dply:site:env-export <site> [--to=path] [--force]
 *
 * Writes to stdout by default — pipe into a file or another tool.
 * --to=path writes directly to disk; refuses to overwrite an
 * existing file unless --force is given. Round-trips with
 * dply:site:env-import.
 *
 * SECURITY: this writes cleartext secret values. There is no --reveal
 * flag because the only useful export is the actual value; we accept
 * that and document it. Operators should treat the output as sensitive.
 */
class ExportSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-export
        {site : Site ID, slug, or name}
        {--to= : Write to this file path instead of stdout}
        {--force : Overwrite the destination file if it exists}';

    protected $description = 'Export site environment variables in .env file format.';

    public function handle(DotEnvFileParser $parser, DotEnvFileWriter $writer): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $variables = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($variables);
        $rendered = $writer->render($variables);

        $to = (string) ($this->option('to') ?? '');
        if ($to === '') {
            $this->getOutput()->write($rendered);

            return self::SUCCESS;
        }

        if (file_exists($to) && ! (bool) $this->option('force')) {
            $this->error("Refusing to overwrite existing file: {$to} (use --force)");

            return self::FAILURE;
        }

        $bytes = file_put_contents($to, $rendered);
        if ($bytes === false) {
            $this->error("Failed to write to: {$to}");

            return self::FAILURE;
        }

        $this->info(sprintf('Exported %d variable(s) to %s.', count($variables), $to));

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
