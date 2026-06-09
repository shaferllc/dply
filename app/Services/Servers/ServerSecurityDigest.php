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
     * @return array<string, mixed>
     */
    public function forServer(Server $server): array
    {
        $snapshot = $this->snapshot($server);
        $checkedAt = $this->parseTime($snapshot['checked_at'] ?? null);
        $neverScanned = $checkedAt === null;
        $staleHours = max(1, (int) config('server_security_digest.stale_scan_hours', 24));
        $stale = $checkedAt !== null && $checkedAt->lt(now()->subHours($staleHours));

        $authFailed = isset($snapshot['auth_failed_lines']) ? (int) $snapshot['auth_failed_lines'] : null;
        $authInvalidUser = isset($snapshot['auth_invalid_user_lines']) ? (int) $snapshot['auth_invalid_user_lines'] : null;
        $authFailedPassword = isset($snapshot['auth_failed_password_lines']) ? (int) $snapshot['auth_failed_password_lines'] : null;
        $authFailedRecent = isset($snapshot['auth_failed_recent']) ? (int) $snapshot['auth_failed_recent'] : null;
        $fail2banActive = is_string($snapshot['fail2ban_active'] ?? null) ? $snapshot['fail2ban_active'] : null;
        $jails = is_array($snapshot['fail2ban_jails'] ?? null) ? $snapshot['fail2ban_jails'] : [];
        $jailRows = is_array($snapshot['fail2ban_jail_rows'] ?? null) ? $snapshot['fail2ban_jail_rows'] : [];
        $ufwActive = is_string($snapshot['ufw_active'] ?? null) ? $snapshot['ufw_active'] : null;
        $sshdPasswordAuth = is_string($snapshot['sshd_password_auth'] ?? null) ? $snapshot['sshd_password_auth'] : null;
        $sshdPermitRoot = is_string($snapshot['sshd_permit_root'] ?? null) ? $snapshot['sshd_permit_root'] : null;

        $bannedNow = (int) collect($jailRows)->sum(fn (array $row): int => (int) ($row['currently_banned'] ?? 0));

        $alerts = array_merge(
            $this->scanAlerts($neverScanned, $stale),
            $this->authAlerts($authFailed, $authFailedRecent, $server),
            $this->fail2banAlerts($fail2banActive, $bannedNow, $server),
            $this->hardeningAlerts($sshdPasswordAuth, $sshdPermitRoot, $ufwActive, $server),
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

        if ($overall === 'ok') {
            foreach ($alerts as $alert) {
                if ($alert['severity'] === 'info') {
                    $overall = 'info';
                    break;
                }
            }
        }

        $warningThreshold = max(1, (int) config('server_security_digest.thresholds.auth_failed_warning', 50));
        $criticalThreshold = max(1, (int) config('server_security_digest.thresholds.auth_failed_critical', 200));

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'summary' => [
                'auth_failed_total' => $authFailed,
                'auth_failed_recent' => $authFailedRecent,
                'auth_invalid_user' => $authInvalidUser,
                'auth_failed_password' => $authFailedPassword,
                'banned_now' => $bannedNow,
                'jail_count' => count($jails),
                'warning_threshold' => $warningThreshold,
                'critical_threshold' => $criticalThreshold,
            ],
            'scan' => [
                'checked_at' => $checkedAt,
                'never_scanned' => $neverScanned,
                'stale' => $stale,
                'stale_hours' => $staleHours,
            ],
            'auth' => [
                'failed_lines' => $authFailed,
                'invalid_user_lines' => $authInvalidUser,
                'failed_password_lines' => $authFailedPassword,
                'recent_lines' => $authFailedRecent,
                'severity' => $this->authSeverity($authFailed),
            ],
            'fail2ban' => [
                'active' => $fail2banActive,
                'jails' => $jails,
                'jail_rows' => $jailRows,
                'banned_now' => $bannedNow,
            ],
            'firewall' => [
                'ufw_active' => $ufwActive,
            ],
            'sshd' => [
                'password_authentication' => $sshdPasswordAuth,
                'permit_root_login' => $sshdPermitRoot,
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
    private function authAlerts(?int $failedLines, ?int $recentLines, Server $server): array
    {
        $alerts = [];
        $warning = max(1, (int) config('server_security_digest.thresholds.auth_failed_warning', 50));
        $critical = max(1, (int) config('server_security_digest.thresholds.auth_failed_critical', 200));
        $recentWarning = max(1, (int) config('server_security_digest.thresholds.auth_failed_recent_warning', 25));

        if ($failedLines === null) {
            return [];
        }

        if ($failedLines >= $critical) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('High SSH auth failure volume'),
                'message' => __(':count Failed password / Invalid user lines in auth.log — review system logs.', ['count' => $failedLines]),
                'href' => route('servers.logs', $server),
                'link_label' => __('Open logs'),
            ];
        } elseif ($failedLines >= $warning) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Elevated SSH auth failures'),
                'message' => __(':count matching lines in auth.log — confirm fail2ban is banning offenders.', ['count' => $failedLines]),
                'href' => route('servers.logs', $server),
                'link_label' => __('Open logs'),
            ];
        }

        if ($recentLines !== null && $recentLines >= $recentWarning && ($failedLines === null || $failedLines < $warning)) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Recent brute-force burst'),
                'message' => __(':count matching lines in the last ~5000 auth.log entries — watch for ongoing scans.', ['count' => $recentLines]),
                'href' => route('servers.logs', $server),
                'link_label' => __('Open logs'),
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function fail2banAlerts(?string $active, int $bannedNow, Server $server): array
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

        if ($bannedNow >= 1) {
            return [[
                'severity' => 'info',
                'title' => trans_choice(':count IP currently banned|:count IPs currently banned', $bannedNow, ['count' => $bannedNow]),
                'message' => __('fail2ban is actively blocking offenders — review jails below if bans look unexpected.'),
                'href' => route('servers.firewall', $server),
                'link_label' => __('Open firewall'),
            ]];
        }

        return [];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function hardeningAlerts(?string $passwordAuth, ?string $permitRoot, ?string $ufwActive, Server $server): array
    {
        $alerts = [];

        if ($passwordAuth !== null && in_array(strtolower($passwordAuth), ['yes', 'true', '1'], true)) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Password authentication enabled'),
                'message' => __('sshd allows password logins — prefer key-only access and disable PasswordAuthentication when possible.'),
                'href' => route('servers.ssh-keys', $server),
                'link_label' => __('SSH keys'),
            ];
        }

        if ($permitRoot !== null && strtolower($permitRoot) === 'yes') {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Root SSH login permitted'),
                'message' => __('PermitRootLogin is yes — use a deploy user + sudo and set PermitRootLogin no when you can.'),
                'href' => route('servers.settings', $server),
                'link_label' => __('Settings'),
            ];
        }

        if ($ufwActive === 'inactive') {
            $alerts[] = [
                'severity' => 'info',
                'title' => __('UFW is inactive'),
                'message' => __('Host firewall is installed but not enforcing rules — review Firewall rules before exposing services.'),
                'href' => route('servers.firewall', $server),
                'link_label' => __('Open firewall'),
            ];
        } elseif ($ufwActive === 'missing') {
            $alerts[] = [
                'severity' => 'info',
                'title' => __('UFW not detected'),
                'message' => __('No ufw binary on PATH — confirm another firewall or provider security group covers this host.'),
                'href' => route('servers.firewall', $server),
                'link_label' => __('Open firewall'),
            ];
        }

        return $alerts;
    }

    private function authSeverity(?int $failedLines): string
    {
        if ($failedLines === null) {
            return 'unknown';
        }

        $warning = max(1, (int) config('server_security_digest.thresholds.auth_failed_warning', 50));
        $critical = max(1, (int) config('server_security_digest.thresholds.auth_failed_critical', 200));

        if ($failedLines >= $critical) {
            return 'critical';
        }

        if ($failedLines >= $warning) {
            return 'warning';
        }

        return 'ok';
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
