<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\Server;
use App\Models\SiteCertificate;
use Carbon\CarbonImmutable;

/**
 * Server-scoped TLS certificate inventory — expiry, challenge type, failures.
 */
final class ServerCertificateInventory
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     summary: array{total: int, active: int, expiring: int, failed: int, pending: int, expired: int, renewable: int, sites_with_certs: int, sites_total: int},
     *     breakdown: array{providers: array<string, int>, challenges: array<string, int>},
     *     items: list<array<string, mixed>>,
     *     warning_days: int,
     *     critical_days: int,
     * }
     */
    public function forServer(Server $server): array
    {
        $sites = $server->sites()->get(['id', 'name', 'server_id']);
        $siteIds = $sites->pluck('id');
        $warningDays = max(1, (int) config('server_cert_inventory.warning_days', 30));
        $criticalDays = max(1, (int) config('server_cert_inventory.critical_days', 7));

        if ($siteIds->isEmpty()) {
            return $this->emptyReport();
        }

        $certs = SiteCertificate::query()
            ->with('site:id,name,server_id')
            ->whereIn('site_id', $siteIds)
            ->whereNotIn('status', [SiteCertificate::STATUS_REMOVED])
            ->orderBy('expires_at')
            ->get();

        $items = [];
        $expiring = 0;
        $failed = 0;
        $pending = 0;
        $active = 0;
        $expired = 0;
        $renewable = 0;
        $providers = [];
        $challenges = [];
        $sitesWithCerts = [];

        foreach ($certs as $cert) {
            $site = $cert->site;
            $domains = $cert->domainHostnames();
            $domain = $domains[0] ?? __('Unknown domain');
            $daysLeft = $cert->expires_at !== null
                ? (int) now()->diffInDays($cert->expires_at, false)
                : null;

            $severity = 'ok';
            if ($cert->status === SiteCertificate::STATUS_FAILED) {
                $failed++;
                $severity = 'critical';
            } elseif ($cert->status === SiteCertificate::STATUS_EXPIRED) {
                $expired++;
                $severity = 'critical';
            } elseif (in_array($cert->status, [SiteCertificate::STATUS_PENDING, SiteCertificate::STATUS_ISSUED, SiteCertificate::STATUS_INSTALLING], true)) {
                $pending++;
                $severity = 'warning';
            } elseif ($cert->status === SiteCertificate::STATUS_ACTIVE) {
                $active++;
                if ($daysLeft !== null && $daysLeft <= $criticalDays) {
                    $expiring++;
                    $severity = 'critical';
                } elseif ($daysLeft !== null && $daysLeft <= $warningDays) {
                    $expiring++;
                    $severity = 'warning';
                }
            }

            $canRenew = $this->isRenewable($cert);
            if ($canRenew) {
                $renewable++;
            }

            $provider = (string) $cert->provider_type;
            $challenge = (string) $cert->challenge_type;
            $providers[$provider] = ($providers[$provider] ?? 0) + 1;
            $challenges[$challenge] = ($challenges[$challenge] ?? 0) + 1;

            if ($site !== null) {
                $sitesWithCerts[(string) $site->id] = true;
            }

            $items[] = [
                'id' => (string) $cert->id,
                'site_id' => (string) $cert->site_id,
                'site_name' => $site !== null ? (string) $site->name : __('Unknown site'),
                'domain' => $domain,
                'all_domains' => $domains,
                'scope_type' => (string) $cert->scope_type,
                'status' => (string) $cert->status,
                'provider' => $provider,
                'challenge' => $challenge,
                'expires_at' => $cert->expires_at,
                'days_left' => $daysLeft,
                'severity' => $severity,
                'renewable' => $canRenew,
                'href' => $site !== null
                    ? route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'certificates'])
                    : null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = static fn (string $severity): int => match ($severity) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            };
            $bySeverity = $rank($a['severity']) <=> $rank($b['severity']);
            if ($bySeverity !== 0) {
                return $bySeverity;
            }

            return ($a['days_left'] ?? 9999) <=> ($b['days_left'] ?? 9999);
        });

        $alerts = $this->buildAlerts($failed, $expiring, $expired, $server);

        $overall = 'ok';
        foreach ($alerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $overall = 'critical';
                break;
            }
            if ($alert['severity'] === 'warning' && $overall === 'ok') {
                $overall = 'warning';
            }
        }

        arsort($providers);
        arsort($challenges);

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'summary' => [
                'total' => count($items),
                'active' => $active,
                'expiring' => $expiring,
                'failed' => $failed,
                'pending' => $pending,
                'expired' => $expired,
                'renewable' => $renewable,
                'sites_with_certs' => count($sitesWithCerts),
                'sites_total' => $sites->count(),
            ],
            'breakdown' => [
                'providers' => $providers,
                'challenges' => $challenges,
            ],
            'items' => $items,
            'warning_days' => $warningDays,
            'critical_days' => $criticalDays,
        ];
    }

    /**
     * @return array{overall: string, alert_count: int, alerts: array, summary: array, breakdown: array, items: array, warning_days: int, critical_days: int}
     */
    private function emptyReport(): array
    {
        return [
            'overall' => 'ok',
            'alert_count' => 0,
            'alerts' => [],
            'summary' => [
                'total' => 0,
                'active' => 0,
                'expiring' => 0,
                'failed' => 0,
                'pending' => 0,
                'expired' => 0,
                'renewable' => 0,
                'sites_with_certs' => 0,
                'sites_total' => 0,
            ],
            'breakdown' => ['providers' => [], 'challenges' => []],
            'items' => [],
            'warning_days' => (int) config('server_cert_inventory.warning_days', 30),
            'critical_days' => (int) config('server_cert_inventory.critical_days', 7),
        ];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function buildAlerts(int $failed, int $expiring, int $expired, Server $server): array
    {
        $alerts = [];

        if ($failed > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => trans_choice(':count failed certificate|:count failed certificates', $failed, ['count' => $failed]),
                'message' => __('Re-issue from the site Certificates tab or queue a bulk renewal below.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        if ($expired > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => trans_choice(':count expired certificate|:count expired certificates', $expired, ['count' => $expired]),
                'message' => __('Browsers will reject HTTPS until these are renewed.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        if ($expiring > 0) {
            $alerts[] = [
                'severity' => ($failed > 0 || $expired > 0) ? 'warning' : 'critical',
                'title' => trans_choice(':count certificate expiring soon|:count certificates expiring soon', $expiring, ['count' => $expiring]),
                'message' => __('Plan renewal before deploy hooks or browser trust breaks.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        return $alerts;
    }

    /**
     * Back-fill `expires_at`/`days_left` on inventory items from the live on-disk
     * cert scan. Managed certbot-issued certs transition to ACTIVE without the
     * issuance flow ever persisting an expiry to `site_certificates.expires_at`
     * (certbot computes it on-box), so the stored value is null and the Expires
     * column renders "—". The live openssl sweep is the only source of the real
     * notAfter date; when an item's domain matches a scanned cert we copy the
     * expiry across (display-only — the DB row is untouched).
     *
     * No SSH happens here: the caller passes the cached scan rows so the SSH
     * probe stays in the queue job, never the request.
     *
     * @param  list<array<string, mixed>>  $items  items from {@see forServer()}
     * @param  list<array<string, mixed>>  $liveCerts  `certs` from {@see WebserverCertsAggregator::cached()}
     * @return list<array<string, mixed>>
     */
    public function withLiveExpiry(array $items, array $liveCerts): array
    {
        if ($items === [] || $liveCerts === []) {
            return $items;
        }

        $warningDays = max(1, (int) config('server_cert_inventory.warning_days', 30));
        $criticalDays = max(1, (int) config('server_cert_inventory.critical_days', 7));

        // domain (lowercased) => earliest CarbonImmutable expiry from the scan.
        $expiryByDomain = [];
        foreach ($liveCerts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $expiresAt = $row['expires_at'] ?? null;
            if (! $expiresAt instanceof CarbonImmutable) {
                continue;
            }
            foreach ($this->liveCertDomains($row) as $domain) {
                if (! isset($expiryByDomain[$domain]) || $expiresAt->lt($expiryByDomain[$domain])) {
                    $expiryByDomain[$domain] = $expiresAt;
                }
            }
        }

        if ($expiryByDomain === []) {
            return $items;
        }

        foreach ($items as &$item) {
            // Only fill the gap — never overwrite a real stored expiry.
            if (($item['expires_at'] ?? null) !== null) {
                continue;
            }

            $match = null;
            foreach ((array) ($item['all_domains'] ?? []) as $domain) {
                if (! is_string($domain)) {
                    continue;
                }
                $domain = strtolower(trim($domain));
                if ($domain !== '' && isset($expiryByDomain[$domain])) {
                    if ($match === null || $expiryByDomain[$domain]->lt($match)) {
                        $match = $expiryByDomain[$domain];
                    }
                }
            }

            if ($match === null) {
                continue;
            }

            $daysLeft = (int) now()->diffInDays($match, false);
            $item['expires_at'] = $match;
            $item['days_left'] = $daysLeft;
            $item['expires_at_source'] = 'live_scan';

            // Re-derive display severity for active certs now that we know the
            // real expiry (failed/expired/pending keep their status-driven tone).
            if (($item['status'] ?? '') === SiteCertificate::STATUS_ACTIVE && ($item['severity'] ?? 'ok') === 'ok') {
                if ($daysLeft <= $criticalDays) {
                    $item['severity'] = 'critical';
                } elseif ($daysLeft <= $warningDays) {
                    $item['severity'] = 'warning';
                }
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Candidate hostnames a live-scanned cert covers: the subject CN plus the
     * Let's Encrypt live-directory name embedded in the path. Used to match a
     * scan row back to an inventory item by domain.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function liveCertDomains(array $row): array
    {
        $domains = [];

        $subject = is_string($row['subject'] ?? null) ? $row['subject'] : '';
        if ($subject !== '' && preg_match('/CN\s*=\s*([^,\/]+)/i', $subject, $m)) {
            $domains[] = trim($m[1]);
        }

        // /etc/letsencrypt/live/<domain>/fullchain.pem -> <domain>
        $path = is_string($row['path'] ?? null) ? $row['path'] : '';
        if ($path !== '' && preg_match('#/etc/letsencrypt/live/([^/]+)/#', $path, $m)) {
            // Strip certbot's "-0001" dedupe suffix on renewed lineages.
            $domains[] = preg_replace('/-\d{4}$/', '', $m[1]) ?? $m[1];
        }

        return collect($domains)
            ->filter(fn (mixed $d): bool => is_string($d) && trim($d) !== '' && ! str_starts_with(trim($d), '*'))
            ->map(fn (string $d): string => strtolower(trim($d)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Queue renewal for one managed certificate when eligible.
     */
    public function queueRenewal(string $certificateId, Server $server): bool
    {
        $siteIds = $server->sites()->pluck('id');
        if ($siteIds->isEmpty()) {
            return false;
        }

        $cert = SiteCertificate::query()
            ->whereIn('site_id', $siteIds)
            ->whereKey($certificateId)
            ->first();

        if ($cert === null || ! $this->isRenewable($cert)) {
            return false;
        }

        $withinDays = (int) config('server_cert_inventory.warning_days', 30);
        $daysLeft = $cert->expires_at !== null
            ? (int) now()->diffInDays($cert->expires_at, false)
            : null;

        $shouldQueue = $cert->status === SiteCertificate::STATUS_FAILED
            || $cert->status === SiteCertificate::STATUS_EXPIRED
            || ($daysLeft !== null && $daysLeft <= $withinDays);

        if (! $shouldQueue) {
            return false;
        }

        ExecuteSiteCertificateJob::dispatch((string) $cert->id);

        return true;
    }

    public function isRenewable(SiteCertificate $cert): bool
    {
        if (in_array($cert->status, [SiteCertificate::STATUS_FAILED, SiteCertificate::STATUS_ACTIVE], true)) {
            return in_array($cert->provider_type, [
                SiteCertificate::PROVIDER_LETSENCRYPT,
                SiteCertificate::PROVIDER_ZEROSSL,
            ], true);
        }

        return $cert->status === SiteCertificate::STATUS_EXPIRED;
    }

    /**
     * Queue renewal jobs for failed or expiring managed certificates on this server.
     *
     * @return array{queued: int, skipped: int}
     */
    public function queueRenewals(Server $server, ?int $withinDays = null): array
    {
        $report = $this->forServer($server);
        $withinDays ??= (int) config('server_cert_inventory.warning_days', 30);

        $queued = 0;
        $skipped = 0;

        foreach ($report['items'] as $item) {
            if (! ($item['renewable'] ?? false)) {
                $skipped++;

                continue;
            }

            $shouldQueue = ($item['status'] ?? '') === SiteCertificate::STATUS_FAILED
                || (($item['days_left'] ?? null) !== null && (int) $item['days_left'] <= $withinDays);

            if (! $shouldQueue) {
                $skipped++;

                continue;
            }

            ExecuteSiteCertificateJob::dispatch($item['id']);
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }
}
