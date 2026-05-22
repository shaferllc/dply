<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SiteRedirectKind;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Export a site's HTTP redirects as CSV.
 *
 *   dply:site:redirect-export <site> [--to=path] [--force]
 *
 * Internal rewrites are skipped (the bulk-import format is HTTP-only).
 * Output is `from,to,code` per line. Round-trips with redirect-import.
 */
class ExportSiteRedirectsCommand extends Command
{
    protected $signature = 'dply:site:redirect-export
        {site : Site ID, slug, or name}
        {--to= : Write to this file instead of stdout}
        {--force : Overwrite the destination file if it exists}';

    protected $description = 'Export a site\'s HTTP redirects as CSV.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $rows = $site->redirects()
            ->where('kind', SiteRedirectKind::Http)
            ->orderBy('sort_order')
            ->get(['from_path', 'to_url', 'status_code']);

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = sprintf('%s,%s,%d', $r->from_path, $r->to_url, (int) $r->status_code);
        }
        $csv = implode("\n", $lines).($lines === [] ? '' : "\n");

        $to = (string) ($this->option('to') ?? '');
        if ($to === '') {
            $this->getOutput()->write($csv);

            return self::SUCCESS;
        }

        if (file_exists($to) && ! (bool) $this->option('force')) {
            $this->error("Refusing to overwrite existing file: {$to} (use --force)");

            return self::FAILURE;
        }

        if (file_put_contents($to, $csv) === false) {
            $this->error("Failed to write to: {$to}");

            return self::FAILURE;
        }

        $this->info(sprintf('Exported %d redirect(s) to %s.', count($lines), $to));

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
