<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Remove a domain from a site.
 *
 *   dply:site:domain-remove <site> <hostname> [--force] [--json]
 *
 * Refuses to remove the last remaining domain (a site with zero
 * domains can't receive HTTP traffic) and refuses to remove the
 * primary domain when other domains exist (operator should
 * promote another to primary first); both refusals can be
 * overridden with --force.
 *
 * No DNS or NGINX teardown — just deletes the row. Operators
 * should follow with the appropriate webserver redeploy when
 * removing a real production domain.
 */
class RemoveSiteDomainCommand extends Command
{
    protected $signature = 'dply:site:domain-remove
        {site : Site ID, slug, or name}
        {hostname : Domain hostname to remove}
        {--force : Remove even when it is the primary or last domain}
        {--json : Output as JSON}';

    protected $description = 'Remove a domain from a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $hostname = strtolower(trim((string) $this->argument('hostname')));
        if ($hostname === '') {
            $this->error('Hostname cannot be empty.');

            return self::FAILURE;
        }

        $domain = $site->domains()->where('hostname', $hostname)->first();
        if ($domain === null) {
            $this->error("Domain not found on this site: {$hostname}");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $totalDomains = $site->domains()->count();
        if ($totalDomains === 1 && ! $force) {
            $this->error('Refusing to remove the only domain on this site (use --force).');

            return self::FAILURE;
        }
        if ($domain->is_primary && $totalDomains > 1 && ! $force) {
            $this->error('Refusing to remove the primary domain while other domains exist (promote another to primary first, or use --force).');

            return self::FAILURE;
        }

        $domain->delete();

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'removed' => $hostname,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Removed domain %s from %s.', $hostname, $site->name));

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
