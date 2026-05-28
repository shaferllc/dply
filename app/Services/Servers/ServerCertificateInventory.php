<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\Server;
use App\Models\SiteCertificate;

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
     *     summary: array{total: int, active: int, expiring: int, failed: int, pending: int},
     *     items: list<array<string, mixed>>,
     * }
     */
    public function forServer(Server $server): array
    {
        $siteIds = $server->sites()->pluck('id');
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

        foreach ($certs as $cert) {
            $site = $cert->site;
            $domain = $cert->domainHostnames()[0] ?? __('Unknown domain');
            $daysLeft = $cert->expires_at !== null
                ? (int) now()->diffInDays($cert->expires_at, false)
                : null;

            $severity = 'ok';
            if ($cert->status === SiteCertificate::STATUS_FAILED) {
                $failed++;
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

            $items[] = [
                'id' => (string) $cert->id,
                'site_id' => (string) $cert->site_id,
                'site_name' => $site !== null ? (string) $site->name : __('Unknown site'),
                'domain' => $domain,
                'status' => (string) $cert->status,
                'provider' => (string) $cert->provider_type,
                'challenge' => (string) $cert->challenge_type,
                'expires_at' => $cert->expires_at,
                'days_left' => $daysLeft,
                'severity' => $severity,
                'renewable' => $this->isRenewable($cert),
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

        $alerts = $this->buildAlerts($failed, $expiring, $server);

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
            ],
            'items' => $items,
            'warning_days' => $warningDays,
            'critical_days' => $criticalDays,
        ];
    }

    /**
     * @return array{overall: string, alert_count: int, alerts: array, summary: array, items: array}
     */
    private function emptyReport(): array
    {
        return [
            'overall' => 'ok',
            'alert_count' => 0,
            'alerts' => [],
            'summary' => ['total' => 0, 'active' => 0, 'expiring' => 0, 'failed' => 0, 'pending' => 0],
            'items' => [],
            'warning_days' => (int) config('server_cert_inventory.warning_days', 30),
            'critical_days' => (int) config('server_cert_inventory.critical_days', 7),
        ];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function buildAlerts(int $failed, int $expiring, Server $server): array
    {
        $alerts = [];

        if ($failed > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => trans_choice(':count failed certificate|:count failed certificates', $failed, ['count' => $failed]),
                'message' => __('Renew or re-issue from the site Certificates tab, or use bulk renew below.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        if ($expiring > 0) {
            $alerts[] = [
                'severity' => $failed > 0 ? 'warning' : 'critical',
                'title' => trans_choice(':count certificate expiring soon|:count certificates expiring soon', $expiring, ['count' => $expiring]),
                'message' => __('Plan renewal before browsers or deploy hooks break HTTPS.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        return $alerts;
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
