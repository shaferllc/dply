<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use Illuminate\Console\Command;

class RemoveSiteTenantCommand extends Command
{
    protected $signature = 'dply:site:tenant-remove
        {site : Site ID, slug, or name}
        {hostname : Tenant hostname}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Remove a tenant domain from a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $hostname = strtolower(trim((string) $this->argument('hostname')));
        $tenant = $site->tenantDomains()->where('hostname', $hostname)->first();
        if ($tenant === null) {
            $this->error("Tenant not found on this site: {$hostname}");

            return self::FAILURE;
        }

        $tenant->delete();

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['site_id' => $site->id, 'removed' => $hostname], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed tenant %s from %s.', $hostname, $site->name));

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
