<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Fail2ban + auth log digest rollup for VM servers.
 */
final class ServerSecurityDigest
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     scan: array{checked_at: ?Carbon, never_scanned: bool, stale: bool},
     *     auth: array{failed_lines: ?int},
     *     fail2ban: array{active: ?string, jails: list<string>},
     * }
     */
    public function forServer(Server $server): array
    {
        $snapshot = $this->snapshot($server);
        $checkedAt = $this->parseTime($snapshot['checked_at'] ?? null);
        $neverScanned = $checkedAt === null;
        $staleHours = max(1, (int) config('server_security_digest.stale_scan_hours', 24));
        $stale = $checkedAt !== null && $checkedAt->lt(now()->subHours($staleHours));

        $authFailed = isset($snapshot['auth_failed_lines']) ? (int) $snapshot['auth_failed_lines'] : null;
        $fail2banActive = is_string($snapshot['fail2ban_active'] ?? null) ? $snapshot['fail2ban_active'] : null;
        $jails = is_array($snapshot['fail2ban_jails'] ?? null) ? $snapshot['fail2ban_jails'] : [];

        $alerts = array_merge(
            $this->scanAlerts($neverScanned, $stale),
            $this->authAlerts($authFailed, $server),
            $this->fail2banAlerts($fail2banActive, $server),
        );

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
            'scan' => [
                'checked_at' => $checkedAt,
                'never_scanned' => $neverScanned,
                'stale' => $stale,
            ],
            'auth' => ['failed_lines' => $authFailed],
            'fail2ban' => [
                'active' => $fail2banActive,
                'jails' => $jails,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $snapshot = $meta['security_digest_snapshot'] ?? [];

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function scanAlerts(bool $neverScanned, bool $stale): array
    {
        if ($neverScanned) {
            return [[
                'severity' => 'warning',
                'title' => __('No security digest scan yet'),
                'message' => __('Run a scan to count SSH brute-force lines in auth.log and read fail2ban status.'),
                'href' => null,
                'link_label' => null,
            ]];
        }

        if ($stale) {
            return [[
                'severity' => 'warning',
                'title' => __('Security digest is stale'),
                'message' => __('Auth log volume may have changed — refresh for current counts.'),
                'href' => null,
                'link_label' => null,
            ]];
        }

        return [];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function authAlerts(?int $failedLines, Server $server): array
    {
        if ($failedLines === null) {
            return [];
        }

        $warning = max(1, (int) config('server_security_digest.thresholds.auth_failed_warning', 50));
        $critical = max(1, (int) config('server_security_digest.thresholds.auth_failed_critical', 200));

        if ($failedLines >= $critical) {
            return [[
                'severity' => 'critical',
                'title' => __('High SSH auth failure volume'),
                'message' => __(':count Failed password / Invalid user lines in auth.log — review system logs.', ['count' => $failedLines]),
                'href' => route('servers.logs', $server),
                'link_label' => __('Open logs'),
            ]];
        }

        if ($failedLines >= $warning) {
            return [[
                'severity' => 'warning',
                'title' => __('Elevated SSH auth failures'),
                'message' => __(':count matching lines in auth.log — confirm fail2ban is banning offenders.', ['count' => $failedLines]),
                'href' => route('servers.logs', $server),
                'link_label' => __('Open logs'),
            ]];
        }

        return [];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function fail2banAlerts(?string $active, Server $server): array
    {
        if ($active === null) {
            return [];
        }

        if ($active === 'missing') {
            return [[
                'severity' => 'warning',
                'title' => __('fail2ban not installed'),
                'message' => __('SSH brute-force traffic is not being jailed on this server.'),
                'href' => route('servers.manage', $server),
                'link_label' => __('Manage'),
            ]];
        }

        if (! in_array($active, ['active', 'running'], true)) {
            return [[
                'severity' => 'critical',
                'title' => __('fail2ban is not running'),
                'message' => __('Service state: :state', ['state' => $active]),
                'href' => route('servers.services', $server),
                'link_label' => __('Open services'),
            ]];
        }

        return [];
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
