<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Read-only rollup of apt patch state, reboot flags, and uptime from the
 * persisted server inventory probe ({@see ServerInventoryProbeScript}).
 */
final class ServerPatchAdvisor
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     os: array{pretty: ?string, key: ?string},
     *     packages: array{total: ?int, security: int, rows: list<array<string, mixed>>, preview_truncated: bool},
     *     reboot: array{required: ?bool},
     *     uptime: array{raw: ?string, load: ?string},
     *     unattended: array{present: bool, enabled: ?bool},
     *     inventory: array{checked_at: ?Carbon, never_scanned: bool, stale: bool, last_apt_update: ?Carbon},
     *     supports_apt: bool,
     * }
     */
    public function forServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];

        $checkedAt = $this->parseTime($meta['inventory_checked_at'] ?? null);
        $lastAptUpdate = $this->parseTime($meta['manage_last_apt_update'] ?? null);
        $neverScanned = $checkedAt === null;
        $staleHours = max(1, (int) config('server_patch_advisor.stale_inventory_hours', 24));
        $stale = $checkedAt !== null && $checkedAt->lt(now()->subHours($staleHours));

        $rows = $this->parseUpgradableRows(
            is_string($meta['inventory_upgradable_preview'] ?? null) ? $meta['inventory_upgradable_preview'] : '',
        );
        $securityCount = collect($rows)->where('is_security', true)->count();
        $previewTruncated = str_contains((string) ($meta['inventory_upgradable_preview'] ?? ''), '[dply] Preview truncated');

        $uptime = $this->parseUptime(
            is_string($meta['inventory_extended_snapshot'] ?? null) ? $meta['inventory_extended_snapshot'] : null,
        );

        $unattended = is_array($meta['manage_unattended_upgrades'] ?? null)
            ? $meta['manage_unattended_upgrades']
            : ['present' => false, 'enabled' => null];

        $rebootRequired = isset($meta['inventory_reboot_required'])
            ? (bool) $meta['inventory_reboot_required']
            : null;

        $totalUpgrades = isset($meta['inventory_upgradable_packages'])
            ? max(0, (int) $meta['inventory_upgradable_packages'])
            : null;

        $supportsApt = $totalUpgrades !== null || $neverScanned === false;

        $alerts = array_merge(
            $this->inventoryAlerts($neverScanned, $stale, $server),
            $this->rebootAlerts($rebootRequired, $server),
            $this->packageAlerts($totalUpgrades, $securityCount, $server),
            $this->aptIndexAlerts($lastAptUpdate, $checkedAt),
        );

        usort($alerts, static function (array $a, array $b): int {
            $rank = static fn (string $severity): int => match ($severity) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            };

            return $rank($a['severity']) <=> $rank($b['severity']);
        });

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
            'os' => [
                'pretty' => is_string($meta['inventory_os_pretty'] ?? null) ? $meta['inventory_os_pretty'] : null,
                'key' => is_string($meta['inventory_os_detected_key'] ?? null) ? $meta['inventory_os_detected_key'] : null,
            ],
            'packages' => [
                'total' => $totalUpgrades,
                'security' => $securityCount,
                'rows' => array_slice($rows, 0, max(5, (int) config('server_patch_advisor.ui.package_rows', 40))),
                'preview_truncated' => $previewTruncated,
            ],
            'reboot' => [
                'required' => $rebootRequired,
            ],
            'uptime' => $uptime,
            'unattended' => [
                'present' => (bool) ($unattended['present'] ?? false),
                'enabled' => array_key_exists('enabled', $unattended) ? $unattended['enabled'] : null,
            ],
            'inventory' => [
                'checked_at' => $checkedAt,
                'never_scanned' => $neverScanned,
                'stale' => $stale,
                'last_apt_update' => $lastAptUpdate,
            ],
            'supports_apt' => $supportsApt,
        ];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function inventoryAlerts(bool $neverScanned, bool $stale, Server $server): array
    {
        $alerts = [];

        if ($neverScanned) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('No inventory scan yet'),
                'message' => __('Run a refresh to read pending apt updates and reboot flags over SSH.'),
                'href' => null,
                'link_label' => null,
            ];
        } elseif ($stale) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Inventory scan is stale'),
                'message' => __('Patch data may be outdated — refresh for a current count.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function rebootAlerts(?bool $rebootRequired, Server $server): array
    {
        if ($rebootRequired !== true) {
            return [];
        }

        return [[
            'severity' => 'critical',
            'title' => __('Reboot required'),
            'message' => __('Kernel or libc updates need a restart. Plan a maintenance window before rebooting.'),
            'href' => route('servers.maintenance', $server),
            'link_label' => __('Open maintenance'),
        ]];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function packageAlerts(?int $total, int $securityCount, Server $server): array
    {
        if ($total === null) {
            return [];
        }

        $alerts = [];

        if ($securityCount > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => trans_choice(':count security update pending|:count security updates pending', $securityCount, ['count' => $securityCount]),
                'message' => __('Review the package list and schedule patching on Manage → Updates.'),
                'href' => route('servers.manage', $server).'?section=updates',
                'link_label' => __('Manage updates'),
            ];
        } elseif ($total > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => trans_choice(':count package update pending|:count package updates pending', $total, ['count' => $total]),
                'message' => __('Non-security upgrades are available.'),
                'href' => route('servers.manage', $server).'?section=updates',
                'link_label' => __('Manage updates'),
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function aptIndexAlerts(?Carbon $lastAptUpdate, ?Carbon $checkedAt): array
    {
        if ($lastAptUpdate === null || $checkedAt === null) {
            return [];
        }

        if ($lastAptUpdate->lt(now()->subDays(7))) {
            return [[
                'severity' => 'warning',
                'title' => __('apt index is old'),
                'message' => __('Last successful `apt update` stamp is more than 7 days ago.'),
                'href' => null,
                'link_label' => null,
            ]];
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseUpgradableRows(string $preview): array
    {
        $rows = [];

        foreach (explode("\n", $preview) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Listing') || str_starts_with($line, '[dply]')) {
                continue;
            }

            if (preg_match('#^([^/\s]+)/(\S+)\s+(\S+)\s+(\S+)(?:\s+\[upgradable from:\s*(.+?)\])?$#', $line, $matches)) {
                $sources = $matches[2];
                $rows[] = [
                    'name' => $matches[1],
                    'sources' => $sources,
                    'new_version' => $matches[3],
                    'arch' => $matches[4],
                    'current_version' => $matches[5] ?? null,
                    'is_security' => (bool) preg_match('/-security|esm-/i', $sources),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{raw: ?string, load: ?string}
     */
    private function parseUptime(?string $extendedSnapshot): array
    {
        if ($extendedSnapshot === null || trim($extendedSnapshot) === '') {
            return ['raw' => null, 'load' => null];
        }

        $parts = preg_split('/\R---\R/', $extendedSnapshot);
        $uptimeBlock = is_array($parts) && isset($parts[1]) ? trim((string) $parts[1]) : trim($extendedSnapshot);
        $firstLine = strtok($uptimeBlock, "\n") ?: $uptimeBlock;

        $load = null;
        if (preg_match('/load average[s]?:\s*(.+)$/i', $firstLine, $m)) {
            $load = trim($m[1]);
        }

        return [
            'raw' => $firstLine !== '' ? $firstLine : null,
            'load' => $load,
        ];
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
