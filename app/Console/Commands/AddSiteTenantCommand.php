<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Support\HostnameValidator;
use Illuminate\Console\Command;

/**
 * Add a tenant domain to a site.
 *
 *   dply:site:tenant-add <site> <hostname> [--key=...] [--label=...] [--comment=...]
 *
 * Hostname must be unique across the site's routing tables.
 */
class AddSiteTenantCommand extends Command
{
    protected $signature = 'dply:site:tenant-add
        {site : Site ID, slug, or name}
        {hostname : Tenant hostname (e.g. customer.example.com)}
        {--key= : Optional tenant key your app uses to resolve the tenant}
        {--label= : Optional friendly label}
        {--comment= : Optional free-text comment for the row}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Add a tenant domain to a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $hostname = strtolower(trim((string) $this->argument('hostname')));
        if (! HostnameValidator::isValid($hostname)) {
            $this->error("Hostname does not look valid: {$hostname}");

            return self::FAILURE;
        }
        if ($this->hostnameAlreadyTaken($hostname)) {
            $this->error("Hostname already used by another routing record: {$hostname}");

            return self::FAILURE;
        }

        $tenant = SiteTenantDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => $hostname,
            'tenant_key' => trim((string) $this->option('key')) ?: null,
            'label' => trim((string) $this->option('label')) ?: null,
            'comment' => trim((string) $this->option('comment')) ?: null,
            'sort_order' => (int) ($site->tenantDomains()->max('sort_order') ?? 0) + 1,
        ]);

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'tenant' => [
                    'id' => $tenant->id,
                    'hostname' => $tenant->hostname,
                    'tenant_key' => $tenant->tenant_key,
                    'label' => $tenant->label,
                    'comment' => $tenant->comment,
                ],
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Added tenant %s to %s.', $hostname, $site->name));

        return self::SUCCESS;
    }

    private function hostnameAlreadyTaken(string $hostname): bool
    {
        return SiteDomain::query()->where('hostname', $hostname)->exists()
            || SiteDomainAlias::query()->where('hostname', $hostname)->exists()
            || SitePreviewDomain::query()->where('hostname', $hostname)->exists()
            || SiteTenantDomain::query()->where('hostname', $hostname)->exists();
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
