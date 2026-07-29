<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Console;

use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Sites\SiteReachabilityChecker;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Console\Command;

/**
 * Explain why TLS is (not) working for a site's hostnames — the diagnostic that
 * the domains tab can't yet show. A site has two kinds of hostname and they get
 * certs in completely different ways:
 *
 *   - testing host (e.g. *.on-dply.cc) → secured by a per-(server, zone)
 *     {@see ServerWildcardCertificate} issued via certbot DNS-01. Its failure
 *     reason lives only in `last_output` with no UI surface — this command
 *     prints it, plus whether a DNS API token is even available for the zone.
 *   - custom/prod domain → per-site {@see SiteCertificate} via certbot HTTP-01,
 *     which needs DNS pointing at the server and port 80 reachable. This runs
 *     the same reachability probe the "Add SSL" modal gates on.
 *
 * Read-only: resolves and reports, dispatches nothing. Pair it with
 * `dply:wildcards:renew` (reissues a failed/expired wildcard) once the cause
 * is understood.
 *
 * Examples:
 *   php artisan dply:ssl:diagnose 01kv3yss7zpj4t5zppep808ah4
 *   php artisan dply:ssl:diagnose tracely-47cafd36.on-dply.cc
 *   php artisan dply:ssl:diagnose example.com --output
 */
class DiagnoseSiteSslCommand extends Command
{
    protected $signature = 'dply:ssl:diagnose
                            {site : Site ULID, testing hostname, or custom domain}
                            {--output : Print the full certbot/DNS last_output blocks}';

    protected $description = 'Diagnose why TLS is or is not working for a site (testing wildcard + custom domains).';

    public function handle(SiteReachabilityChecker $reachability, TestingHostnameProvisioner $routing): int
    {
        $needle = trim((string) $this->argument('site'));
        $site = $this->resolveSite($needle);

        if ($site === null) {
            $this->error("No site found for [{$needle}] — pass a site ULID, a testing hostname, or a custom domain.");

            return self::FAILURE;
        }

        $site->loadMissing(['server', 'organization', 'previewDomains', 'domains']);
        $server = $site->server;

        $this->line(sprintf('<info>Site</info>     %s  (%s)', $site->id, $site->name ?? '—'));
        $this->line(sprintf('<info>Server</info>   %s  (%s, %s)',
            $server?->id ?? '—',
            $server?->name ?? '—',
            $server?->ip_address ?? 'no IP',
        ));
        $this->line(sprintf('<info>Webserver</info> %s', $site->webserver() ?? '—'));
        $this->newLine();

        $this->diagnoseTestingWildcard($site, $routing);
        $this->newLine();
        $this->diagnoseCustomDomains($site, $reachability);

        return self::SUCCESS;
    }

    private function diagnoseTestingWildcard(Site $site, TestingHostnameProvisioner $routing): void
    {
        $this->line('<comment>── Testing host (wildcard TLS) ──</comment>');

        $zone = $site->testingZone();
        $preview = $site->primaryPreviewDomain();

        if ($zone === null) {
            $this->line('  This site has no dply-managed testing hostname — nothing to secure here.');

            return;
        }

        $this->line(sprintf('  Hostname  %s', $preview?->hostname ?? '(unknown)'));
        $this->line(sprintf('  Zone      %s  →  needs an installed *.%s on the server', $zone, $zone));

        $wildcard = ServerWildcardCertificate::query()
            ->where('server_id', $site->server_id)
            ->where('zone', $zone)
            ->first();

        if ($wildcard === null) {
            $this->line('  <error>No wildcard row exists for this (server, zone).</error>');
            $this->line('  → Create + issue it: php artisan dply:wildcards:backfill --server='.$site->server_id);
            $this->reportDnsRouting($site, $routing, $zone);

            return;
        }

        $installed = $wildcard->isInstalled();
        $tag = $installed ? '<info>'.$wildcard->status.'</info>' : '<error>'.$wildcard->status.'</error>';
        $this->line(sprintf('  Cert      %s  (installed: %s)', $tag, $installed ? 'yes' : 'NO'));
        $this->line(sprintf('  Provider  %s  (credential: %s)',
            $wildcard->provider ?: '—',
            $wildcard->provider_credential_id ?: 'none — falls back to services.digitalocean.token',
        ));
        $this->line(sprintf('  Expires   %s', $wildcard->not_after?->toDateTimeString() ?? '—'));
        $this->line(sprintf('  Last req  %s   Last install  %s',
            $wildcard->last_requested_at?->diffForHumans() ?? 'never',
            $wildcard->last_installed_at?->diffForHumans() ?? 'never',
        ));

        if (! $installed) {
            $this->reportDnsRouting($site, $routing, $zone);
            $this->line('  → Reissue: php artisan dply:wildcards:renew  (or backfill --server='.$site->server_id.')');
            $this->printOutput('wildcard', (string) $wildcard->last_output);
        } elseif ($this->option('output')) {
            $this->printOutput('wildcard', (string) $wildcard->last_output);
        }
    }

    private function reportDnsRouting(Site $site, TestingHostnameProvisioner $routing, string $zone): void
    {
        try {
            $route = $routing->testingDnsRoutingForSite($site);
        } catch (\Throwable $e) {
            $this->line('  <error>Could not resolve DNS routing: '.$e->getMessage().'</error>');

            return;
        }

        $hasToken = is_string($route['token'] ?? null) && trim((string) $route['token']) !== '';
        $this->line(sprintf('  DNS-01    provider=%s  token=%s',
            $route['provider'] ?? '—',
            $hasToken ? 'present' : '<error>MISSING</error>',
        ));

        if (! $hasToken) {
            $this->line("  <error>No DNS API token controls zone [{$zone}] — the _acme-challenge TXT can't be written, so issuance throws.</error>");
            $this->line('  → Attach a provider credential whose token controls this zone, or set the provider default token.');
        }
    }

    private function diagnoseCustomDomains(Site $site, SiteReachabilityChecker $reachability): void
    {
        $this->line('<comment>── Custom / prod domains (HTTP-01 per-site certs) ──</comment>');

        $domains = $site->domains;
        if ($domains->isEmpty()) {
            $this->line('  No custom domains attached.');

            return;
        }

        $certs = $site->certificates()->get();

        foreach ($domains as $domain) {
            /** @var SiteDomain $domain */
            $host = $domain->hostname;
            $check = $reachability->checkHostname($site, $host);

            $cert = $certs->first(fn (SiteCertificate $c): bool => in_array($host, $c->domainHostnames(), true));

            $this->newLine();
            $this->line(sprintf('  <info>%s</info>%s', $host, $domain->is_primary ? '  (primary)' : ''));
            $this->line(sprintf('    DNS resolves: %s   points here: %s   HTTP/80: %s',
                $check['resolves'] ? 'yes' : 'NO',
                $check['points_here'] ? 'yes' : 'NO',
                $check['http_ok'] ? 'yes' : 'NO',
            ));
            if (! empty($check['error'])) {
                $this->line('    <error>'.$check['error'].'</error>');
            }

            if ($cert === null) {
                $this->line('    Cert: <error>none requested</error> → use "Add SSL" on the domains tab, or issue via CLI.');

                continue;
            }

            $active = $cert->status === SiteCertificate::STATUS_ACTIVE;
            $tag = $active ? '<info>'.$cert->status.'</info>' : '<error>'.$cert->status.'</error>';
            $this->line(sprintf('    Cert: %s   challenge=%s   expires=%s',
                $tag,
                $cert->challenge_type ?? '—',
                $cert->expires_at?->toDateString() ?? '—',
            ));

            if (! $active || $this->option('output')) {
                $this->printOutput('cert', (string) $cert->last_output);
            }
        }
    }

    private function printOutput(string $label, string $output): void
    {
        $output = trim($output);
        if ($output === '') {
            $this->line("    ({$label} last_output is empty)");

            return;
        }

        if (! $this->option('output')) {
            // Default to the tail — the actionable certbot error is at the end.
            $lines = preg_split('/\r?\n/', $output) ?: [];
            $tail = array_slice($lines, -8);
            $this->line("    last {$label} output (tail; pass --output for full):");
            foreach ($tail as $l) {
                $this->line('      '.$l);
            }

            return;
        }

        $this->line("    full {$label} output:");
        foreach (preg_split('/\r?\n/', $output) ?: [] as $l) {
            $this->line('      '.$l);
        }
    }

    /**
     * Resolve a site by ULID, testing (preview) hostname, or custom domain.
     */
    private function resolveSite(string $needle): ?Site
    {
        if ($site = Site::query()->whereKey($needle)->first()) {
            return $site;
        }

        $host = strtolower($needle);

        $preview = SitePreviewDomain::query()->where('hostname', $host)->first();
        if ($preview && $preview->site) {
            return $preview->site;
        }

        $domain = SiteDomain::query()->where('hostname', $host)->first();
        if ($domain && $domain->site) {
            return $domain->site;
        }

        return null;
    }
}
