<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SupervisorProgram;
use Illuminate\Support\Carbon;

/**
 * Daemon & worker SLO rollup for VM servers — supervisor program health,
 * restart/backoff states, and config drift from the health snapshot.
 */
final class ServerDaemonSloPanel
{
    public function __construct(
        private readonly ServerSupervisorStatusParser $parser,
    ) {}

    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     health: array{checked_at: ?Carbon, never_checked: bool, stale: bool, ok: ?bool, config_drift: bool, summary: ?string},
     *     programs: array{total: int, active: int, unhealthy: int, rows: list<array<string, mixed>>},
     * }
     */
    public function forServer(Server $server): array
    {
        $health = $this->healthSnapshot($server);
        $programs = $this->programs($server, $health);

        $alerts = array_merge(
            $this->healthAlerts($health, $server),
            $this->programAlerts($programs, $server),
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
            'health' => $health,
            'programs' => $programs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthSnapshot(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $snapshot = is_array($meta['supervisor_health'] ?? null) ? $meta['supervisor_health'] : [];

        $checkedAt = $this->parseTime($snapshot['checked_at'] ?? null);
        $neverChecked = $checkedAt === null;
        $staleHours = max(1, (int) config('server_daemon_slo.stale_health_hours', 6));
        $stale = $checkedAt !== null && $checkedAt->lt(now()->subHours($staleHours));

        return [
            'checked_at' => $checkedAt,
            'never_checked' => $neverChecked,
            'stale' => $stale,
            'ok' => array_key_exists('ok', $snapshot) ? (bool) $snapshot['ok'] : null,
            'config_drift' => (bool) ($snapshot['config_drift'] ?? false),
            'summary' => is_string($snapshot['summary'] ?? null) ? $snapshot['summary'] : null,
            'detail' => is_string($snapshot['detail'] ?? null) ? $snapshot['detail'] : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    private function programs(Server $server, array $health): array
    {
        $activePrograms = SupervisorProgram::query()
            ->where('server_id', $server->id)
            ->where('is_active', true)
            ->count();

        $parsed = $this->parser->parseForServer($server, (string) ($health['detail'] ?? ''));
        $unhealthy = collect($parsed)->where('healthy', false)->count();

        $rows = [];
        foreach ($parsed as $row) {
            $rows[] = array_merge($row, [
                'href' => $row['site_id'] !== null
                    ? route('sites.show', ['server' => $server->id, 'site' => $row['site_id'], 'section' => 'workers'])
                    : route('servers.daemons', $server),
            ]);
        }

        return [
            'total' => SupervisorProgram::query()->where('server_id', $server->id)->count(),
            'active' => $activePrograms,
            'unhealthy' => $unhealthy,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function healthAlerts(array $health, Server $server): array
    {
        $alerts = [];

        if ($health['never_checked']) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('No supervisor health check yet'),
                'message' => __('Refresh status over SSH to read queue worker and daemon states.'),
                'href' => null,
                'link_label' => null,
            ];
        } elseif ($health['stale']) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Supervisor snapshot is stale'),
                'message' => __('Worker state may have changed — refresh for a current picture.'),
                'href' => null,
                'link_label' => null,
            ];
        }

        if ($health['ok'] === false) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('Supervisor programs need attention'),
                'message' => $health['summary'] ?? __('One or more managed programs are not RUNNING.'),
                'href' => route('servers.daemons', $server),
                'link_label' => __('Open daemons'),
            ];
        }

        if ($health['config_drift']) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Supervisor config drift'),
                'message' => __('On-disk supervisor files differ from Dply — sync from the Daemons workspace.'),
                'href' => route('servers.daemons', $server),
                'link_label' => __('Open daemons'),
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $programs
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function programAlerts(array $programs, Server $server): array
    {
        if (($programs['unhealthy'] ?? 0) === 0) {
            return [];
        }

        $badStates = collect($programs['rows'] ?? [])
            ->where('healthy', false)
            ->take(3)
            ->map(fn (array $row): string => $row['slug'].' ('.$row['state'].')')
            ->implode(', ');

        return [[
            'severity' => 'critical',
            'title' => trans_choice(
                ':count worker not RUNNING|:count workers not RUNNING',
                (int) $programs['unhealthy'],
                ['count' => (int) $programs['unhealthy']],
            ),
            'message' => $badStates !== '' ? $badStates : __('Inspect supervisor status on the Daemons tab.'),
            'href' => route('servers.daemons', $server),
            'link_label' => __('Open daemons'),
        ]];
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
