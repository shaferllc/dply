<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Print a site's URL(s) for shell composition.
 *
 *   dply:site:url <site>             # primary domain, https
 *   dply:site:url <site> --scheme=http
 *   dply:site:url <site> --all       # one URL per line, primary first
 *   dply:site:url <site> --json
 *
 * Designed for `curl $(dply:site:url my-app)/health` style usage —
 * stdout is JUST the URL, no decoration, no trailing newline beyond
 * what ->line() emits. If the site has no domains, prints nothing
 * to stdout and exits non-zero so scripts can detect it.
 *
 * --all writes one URL per line (primary first) for `for url in
 * $(dply:site:url my-app --all)` loops.
 */
class SiteUrlCommand extends Command
{
    protected $signature = 'dply:site:url
        {site : Site ID, slug, or name}
        {--scheme=https : URL scheme (https or http)}
        {--all : Print all domains, one per line, primary first}
        {--json : Output as JSON}';

    protected $description = 'Print a site\'s primary URL (or all URLs with --all).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $scheme = strtolower((string) ($this->option('scheme') ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $this->error("Invalid --scheme: {$scheme} (must be http or https).");

            return self::FAILURE;
        }

        $all = (bool) $this->option('all');

        $domains = $site->domains()->orderByDesc('is_primary')->orderBy('hostname')->get();
        if ($domains->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'site_id' => $site->id,
                    'urls' => [],
                ], JSON_PRETTY_PRINT));
            }

            return self::FAILURE;
        }

        $urls = $domains->map(fn ($d) => $scheme.'://'.$d->hostname)->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'site_name' => $site->name,
                'scheme' => $scheme,
                'urls' => $urls,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($all) {
            foreach ($urls as $url) {
                $this->line($url);
            }

            return self::SUCCESS;
        }

        $this->line($urls[0]);

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
