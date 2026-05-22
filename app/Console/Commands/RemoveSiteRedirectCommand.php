<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Remove a redirect rule from a site by from_path.
 *
 *   dply:site:redirect-remove <site> <from> [--no-apply]
 *
 * If multiple rules share the same from_path, all are removed.
 */
class RemoveSiteRedirectCommand extends Command
{
    protected $signature = 'dply:site:redirect-remove
        {site : Site ID, slug, or name}
        {from : Source path of the redirect to remove}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Remove redirect rule(s) from a site by source path.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $from = trim((string) $this->argument('from'));
        $deleted = $site->redirects()->where('from_path', $from)->delete();

        if ($deleted === 0) {
            $this->error("No redirect found for {$from} on {$site->name}.");

            return self::FAILURE;
        }

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['site_id' => $site->id, 'removed' => $from, 'count' => $deleted], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed %d redirect(s) for %s on %s.', $deleted, $from, $site->name));

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
