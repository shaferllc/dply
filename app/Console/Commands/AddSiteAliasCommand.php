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
 * Add a domain alias to a site.
 *
 *   dply:site:alias-add <site> <hostname> [--label=...] [--comment=...]
 *
 * Aliases extend the webserver server_name list. Hostname must be unique
 * across every routing table on the site (domains, aliases, preview,
 * tenants). On supported runtimes, also dispatches a webserver config
 * apply so the change lands on disk; pass --no-apply to skip.
 */
class AddSiteAliasCommand extends Command
{
    protected $signature = 'dply:site:alias-add
        {site : Site ID, slug, or name}
        {hostname : Alias hostname (e.g. alt.example.com)}
        {--label= : Optional friendly label}
        {--comment= : Optional free-text comment for the row}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Add a domain alias to a site.';

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

        $alias = SiteDomainAlias::query()->create([
            'site_id' => $site->id,
            'hostname' => $hostname,
            'label' => trim((string) $this->option('label')) ?: null,
            'comment' => trim((string) $this->option('comment')) ?: null,
            'sort_order' => (int) ($site->domainAliases()->max('sort_order') ?? 0) + 1,
        ]);

        $this->maybeDispatchApply($site);

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'alias' => [
                    'id' => $alias->id,
                    'hostname' => $alias->hostname,
                    'label' => $alias->label,
                    'comment' => $alias->comment,
                ],
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Added alias %s to %s.', $hostname, $site->name));

        return self::SUCCESS;
    }

    private function hostnameAlreadyTaken(string $hostname): bool
    {
        return SiteDomain::query()->where('hostname', $hostname)->exists()
            || SiteDomainAlias::query()->where('hostname', $hostname)->exists()
            || SitePreviewDomain::query()->where('hostname', $hostname)->exists()
            || SiteTenantDomain::query()->where('hostname', $hostname)->exists();
    }

    private function maybeDispatchApply(Site $site): void
    {
        if ((bool) $this->option('no-apply')) {
            return;
        }
        if ($site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
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
