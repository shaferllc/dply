<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IssueServerWildcardCertificateJob;
use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Console\Command;

/**
 * Backfill per-(server, zone) wildcard TLS certificates for existing testing
 * sites (e.g. *.on-dply.com). Groups testing-hostname sites by server + zone,
 * creates the {@see ServerWildcardCertificate} row from a representative site's
 * DNS routing, and dispatches issuance. Once a wildcard is active the next
 * webserver config apply prefers it over any legacy per-host preview cert.
 *
 * Only certbot-managed webservers are covered (nginx / openlitespeed / apache,
 * and Caddy behind an edge proxy). Plain Caddy auto-HTTPS boxes self-manage TLS
 * and are skipped, as are headless/worker hosts.
 *
 * Idempotent — re-running reuses existing rows and the issue job is locked.
 *
 * Examples:
 *   php artisan dply:wildcards:backfill --dry-run
 *   php artisan dply:wildcards:backfill --server=01kt2y64...
 */
class BackfillServerWildcardCertificatesCommand extends Command
{
    protected $signature = 'dply:wildcards:backfill
                            {--server= : Limit to sites on one server (ULID)}
                            {--dry-run : List what would change without writing anything}';

    protected $description = 'Backfill per-server wildcard TLS certificates for existing testing sites.';

    public function handle(TestingHostnameProvisioner $routing): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no changes will be written.');
        }

        $query = Site::query()
            ->whereHas('previewDomains')
            ->with(['server', 'previewDomains', 'domains', 'organization']);

        if ($server = $this->option('server')) {
            $query->where('server_id', $server);
        }

        // (server_id|zone) => representative Site, to issue one wildcard per group.
        $groups = [];
        foreach ($query->get() as $site) {
            if (! $this->eligible($site)) {
                continue;
            }

            $zone = $site->testingZone();
            $key = $site->server_id.'|'.$zone;
            $groups[$key] ??= $site;
        }

        if ($groups === []) {
            $this->info('No eligible testing sites found.');

            return self::SUCCESS;
        }

        foreach ($groups as $key => $site) {
            [$serverId, $zone] = explode('|', $key, 2);

            $existing = ServerWildcardCertificate::query()
                ->where('server_id', $serverId)
                ->where('zone', $zone)
                ->first();

            if ($existing?->isInstalled()) {
                $this->line(sprintf('skip *.%s on %s — already installed', $zone, $serverId));

                continue;
            }

            $route = $routing->testingDnsRoutingForSite($site);
            $this->line(sprintf(
                '%s *.%s on %s via %s',
                $dryRun ? '[dry-run] would issue' : 'issuing',
                $zone,
                $serverId,
                $route['provider'],
            ));

            if ($dryRun) {
                continue;
            }

            ServerWildcardCertificate::query()->updateOrCreate(
                ['server_id' => $serverId, 'zone' => $zone],
                [
                    'provider' => $route['provider'],
                    'provider_credential_id' => $route['credential']?->id,
                    'status' => $existing?->status ?? ServerWildcardCertificate::STATUS_PENDING,
                    'live_directory' => $zone,
                ],
            );

            IssueServerWildcardCertificateJob::dispatch($serverId, $zone);
        }

        $this->info(sprintf('%s %d wildcard group(s).', $dryRun ? 'Would process' : 'Processed', count($groups)));

        return self::SUCCESS;
    }

    /**
     * Certbot-managed testing site on a ready server — the only kind that
     * references an on-disk wildcard.
     */
    private function eligible(Site $site): bool
    {
        if ($site->testingZone() === null || $site->server === null || ! $site->server->isReady()) {
            return false;
        }

        if ($site->isHeadless()) {
            return false;
        }

        $webserver = $site->webserver();
        if ($webserver === 'caddy' && ! $site->server->hasEdgeProxy()) {
            return false;
        }

        return in_array($webserver, ['nginx', 'caddy', 'openlitespeed', 'apache'], true);
    }
}
