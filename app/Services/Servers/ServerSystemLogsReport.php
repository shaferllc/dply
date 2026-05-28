<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Workspace overview for the server Logs page — source catalog, live viewer
 * status, and SSH/readiness context without duplicating fetch logic.
 */
final class ServerSystemLogsReport
{
    /**
     * @param  array<string, array<string, mixed>>  $logSources
     * @param  array{
     *     log_key: string,
     *     log_total_lines: int,
     *     log_filtered_lines: int,
     *     log_last_fetched_at: ?string,
     *     log_auto_refresh: bool,
     *     log_auto_refresh_seconds: int,
     *     log_time_range_minutes: ?int,
     *     remote_log_error: ?string,
     *     log_last_fetch_truncated: bool,
     *     log_last_fetch_raw_bytes: int,
     *     log_broadcast_subscribable: bool,
     * }  $viewer
     * @return array{
     *     overall: string,
     *     ops_ready: bool,
     *     is_deployer: bool,
     *     ssh_required_for_active: bool,
     *     summary: array{
     *         source_count: int,
     *         site_source_count: int,
     *         group_count: int,
     *         filtered_lines: int,
     *         total_lines: int,
     *     },
     *     active_source: array{key: string, label: string, type: string, group: string, path: ?string},
     *     source_rows: list<array{key: string, label: string, group: string, group_label: string, type: string, path: ?string, active: bool, ssh_required: bool}>,
     *     viewer: array<string, mixed>,
     * }
     */
    public function build(Server $server, array $logSources, array $viewer, ?User $user = null): array
    {
        $user ??= auth()->user();
        $isDeployer = $user !== null
            && $server->organization_id
            && $server->organization?->userIsDeployer($user);

        $opsReady = $server->isReady() && filled($server->ssh_private_key);
        $logKey = (string) ($viewer['log_key'] ?? '');
        $activeDef = $logSources[$logKey] ?? [];
        $activeType = (string) ($activeDef['type'] ?? 'file');
        $sshRequiredForActive = ! in_array($activeType, ['dply', 'dply_site'], true);

        $sourceRows = [];
        $siteSourceCount = 0;
        $groups = [];

        foreach ($logSources as $key => $def) {
            if (! is_array($def)) {
                continue;
            }

            $group = (string) ($def['group'] ?? 'other');
            $type = (string) ($def['type'] ?? 'file');
            $groups[$group] = ($groups[$group] ?? 0) + 1;

            if ($group === 'sites' || $group === 'site') {
                $siteSourceCount++;
            }

            $sourceRows[] = [
                'key' => (string) $key,
                'label' => (string) ($def['label'] ?? $key),
                'group' => $group,
                'group_label' => $this->groupLabel($group),
                'type' => $type,
                'path' => is_string($def['path'] ?? null) ? $def['path'] : null,
                'active' => $key === $logKey,
                'ssh_required' => ! in_array($type, ['dply', 'dply_site'], true),
            ];
        }

        $remoteError = is_string($viewer['remote_log_error'] ?? null) ? $viewer['remote_log_error'] : null;
        $blocked = ($isDeployer && $sshRequiredForActive)
            || ($sshRequiredForActive && ! $opsReady);

        $overall = 'ready';
        if ($blocked) {
            $overall = 'blocked';
        } elseif ($remoteError !== null && $remoteError !== '') {
            $overall = 'degraded';
        }

        return [
            'overall' => $overall,
            'ops_ready' => $opsReady,
            'is_deployer' => (bool) $isDeployer,
            'ssh_required_for_active' => $sshRequiredForActive,
            'summary' => [
                'source_count' => count($sourceRows),
                'site_source_count' => $siteSourceCount,
                'group_count' => count($groups),
                'filtered_lines' => (int) ($viewer['log_filtered_lines'] ?? 0),
                'total_lines' => (int) ($viewer['log_total_lines'] ?? 0),
            ],
            'active_source' => [
                'key' => $logKey,
                'label' => (string) ($activeDef['label'] ?? $logKey),
                'type' => $activeType,
                'group' => (string) ($activeDef['group'] ?? 'other'),
                'path' => is_string($activeDef['path'] ?? null) ? $activeDef['path'] : null,
            ],
            'source_rows' => $sourceRows,
            'viewer' => [
                'last_fetched_at' => $this->parseTimestamp($viewer['log_last_fetched_at'] ?? null),
                'auto_refresh' => (bool) ($viewer['log_auto_refresh'] ?? false),
                'auto_refresh_seconds' => (int) ($viewer['log_auto_refresh_seconds'] ?? 30),
                'time_range_minutes' => $viewer['log_time_range_minutes'] ?? null,
                'error' => $remoteError,
                'truncated' => (bool) ($viewer['log_last_fetch_truncated'] ?? false),
                'raw_bytes' => (int) ($viewer['log_last_fetch_raw_bytes'] ?? 0),
                'broadcast_subscribable' => (bool) ($viewer['log_broadcast_subscribable'] ?? false),
            ],
        ];
    }

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'dply' => __('Dply'),
            'nginx' => __('Nginx'),
            'apache' => __('Apache'),
            'openlitespeed' => __('OpenLiteSpeed'),
            'traefik' => __('Traefik'),
            'haproxy' => __('HAProxy'),
            'php' => __('PHP'),
            'database' => __('Database'),
            'services' => __('Services'),
            'daemons' => __('Daemons'),
            'system' => __('System'),
            'security' => __('Security'),
            'ssl' => __('SSL'),
            'sites' => __('Sites'),
            'site' => __('Site'),
            default => ucfirst(str_replace('_', ' ', $group)),
        };
    }

    private function parseTimestamp(mixed $value): ?Carbon
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
