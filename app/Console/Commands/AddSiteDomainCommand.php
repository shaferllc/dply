<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Add a domain to a site.
 *
 *   dply:site:domain-add <site> <hostname> [--primary] [--www-redirect] [--json]
 *
 * Inserts a row in site_domains. --primary clears the flag on the
 * site's other domains and sets it on the new one (atomically, in
 * a transaction). --www-redirect controls the apex/www redirect
 * flag on the new row.
 *
 * Hostname is normalized: lowercased, trimmed of leading/trailing
 * whitespace, and the leading "https://" or "http://" scheme is
 * stripped to keep the canonical form bare.
 *
 * Refuses to add a duplicate hostname (same hostname already exists
 * on this site). Site-wide hostname uniqueness is enforced upstream
 * by the database unique index.
 */
class AddSiteDomainCommand extends Command
{
    protected $signature = 'dply:site:domain-add
        {site : Site ID, slug, or name}
        {hostname : Domain hostname (e.g. example.com)}
        {--primary : Mark this domain as the site\'s primary (clears the flag on others)}
        {--www-redirect : Enable apex/www redirect on this domain}
        {--json : Output as JSON}';

    protected $description = 'Add a domain to a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $hostname = $this->normalizeHostname((string) $this->argument('hostname'));
        if ($hostname === '') {
            $this->error('Hostname cannot be empty.');

            return self::FAILURE;
        }
        if (! $this->hostnameLooksValid($hostname)) {
            $this->error("Hostname does not look valid: {$hostname}");

            return self::FAILURE;
        }

        if ($site->domains()->where('hostname', $hostname)->exists()) {
            $this->error("Domain already exists on this site: {$hostname}");

            return self::FAILURE;
        }

        $primary = (bool) $this->option('primary');
        $wwwRedirect = (bool) $this->option('www-redirect');

        $domain = DB::transaction(function () use ($site, $hostname, $primary, $wwwRedirect) {
            if ($primary) {
                $site->domains()->update(['is_primary' => false]);
            }

            return $site->domains()->create([
                'hostname' => $hostname,
                'is_primary' => $primary,
                'www_redirect' => $wwwRedirect,
            ]);
        });

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'domain' => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'is_primary' => (bool) $domain->is_primary,
                'www_redirect' => (bool) $domain->www_redirect,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Added domain %s%s to %s.',
            $hostname,
            $primary ? ' (primary)' : '',
            $site->name,
        ));

        return self::SUCCESS;
    }

    private function normalizeHostname(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = (string) preg_replace('#^https?://#', '', $h);
        $h = rtrim($h, '/');

        return $h;
    }

    private function hostnameLooksValid(string $hostname): bool
    {
        // Liberal RFC-ish check: dots, alnum, hyphens; at least one dot.
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname);
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
