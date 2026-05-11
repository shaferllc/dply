<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Remove a domain alias from a site.
 *
 *   dply:site:alias-remove <site> <hostname> [--no-apply] [--json]
 *
 * On supported runtimes, dispatches a webserver config apply after the
 * delete so the change lands on disk.
 */
class RemoveSiteAliasCommand extends Command
{
    protected $signature = 'dply:site:alias-remove
        {site : Site ID, slug, or name}
        {hostname : Alias hostname}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Remove a domain alias from a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $hostname = strtolower(trim((string) $this->argument('hostname')));
        $alias = $site->domainAliases()->where('hostname', $hostname)->first();
        if ($alias === null) {
            $this->error("Alias not found on this site: {$hostname}");

            return self::FAILURE;
        }

        $alias->delete();

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['site_id' => $site->id, 'removed' => $hostname], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed alias %s from %s.', $hostname, $site->name));

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
