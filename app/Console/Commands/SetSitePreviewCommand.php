<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Support\HostnameValidator;
use Illuminate\Console\Command;

/**
 * Upsert the primary preview hostname for a site.
 *
 *   dply:site:preview-set <site> <hostname> [--label=...] [--auto-ssl] [--https-redirect]
 *
 * Idempotent: re-running with the same hostname updates the existing
 * preview row in place. Other preview rows lose their primary flag.
 */
class SetSitePreviewCommand extends Command
{
    protected $signature = 'dply:site:preview-set
        {site : Site ID, slug, or name}
        {hostname : Preview hostname (e.g. preview.example.dply.cc)}
        {--label=Managed preview : Friendly label for the preview row}
        {--auto-ssl : Automatically request SSL once reachable}
        {--https-redirect : Redirect preview traffic to HTTPS once SSL is active}
        {--no-apply : Skip the webserver config apply}
        {--json : Output as JSON}';

    protected $description = 'Set the primary preview hostname for a site.';

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

        SitePreviewDomain::query()
            ->where('site_id', $site->id)
            ->update(['is_primary' => false]);

        $preview = SitePreviewDomain::query()->updateOrCreate([
            'site_id' => $site->id,
            'hostname' => $hostname,
        ], [
            'label' => trim((string) ($this->option('label') ?: 'Managed preview')),
            'dns_status' => $site->testingHostnameStatus() ?? 'pending',
            'ssl_status' => $site->ssl_status,
            'is_primary' => true,
            'auto_ssl' => (bool) $this->option('auto-ssl'),
            'https_redirect' => (bool) $this->option('https-redirect'),
            'managed_by_dply' => true,
        ]);

        if (! (bool) $this->option('no-apply') && $site->server?->hostCapabilities()->supportsWebserverProvisioning()) {
            ApplySiteWebserverConfigJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'preview' => [
                    'id' => $preview->id,
                    'hostname' => $preview->hostname,
                    'label' => $preview->label,
                    'auto_ssl' => (bool) $preview->auto_ssl,
                    'https_redirect' => (bool) $preview->https_redirect,
                ],
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Preview hostname set to %s on %s.', $hostname, $site->name));

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
