<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshSession;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\TeamSshKey;
use App\Models\UserSshKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only SSH access graph — who has keys on this server, sync state, and review dates.
 */
final class ServerSshAccessGraph
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     sync: array{disabled: bool, last_status: ?string, last_finished_at: ?Carbon},
     *     drift: array{status: ?string, finished_at: ?Carbon},
     *     summary: array{total: int, by_source: array<string, int>, review_overdue: int, never_synced: int, active_sessions: int, platform_access_recent: int},
     *     sessions: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>,
     * }
     */
    /** @return array<string, mixed> */
    public function forServer(Server $server, ?ServerSshAccessContext $context = null): array
    {
        $context ??= ServerSshAccessContext::load($server);

        $keys = $context->authorizedKeys
            ->sortBy([
                ['target_linux_user', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        $rows = [];
        $bySource = [];
        $reviewOverdue = 0;
        $neverSynced = 0;

        foreach ($keys as $key) {
            $source = $this->sourceLabel($key);
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            if ($key->synced_at === null) {
                $neverSynced++;
            }

            if ($key->review_after !== null && $key->review_after->isPast()) {
                $reviewOverdue++;
            }

            $rows[] = [
                'id' => (string) $key->id,
                'name' => (string) $key->name,
                'source' => $source,
                'target_linux_user' => (string) ($key->target_linux_user ?: $server->ssh_user),
                'synced_at' => $key->synced_at,
                'review_after' => $key->review_after,
                'review_overdue' => $key->review_after !== null && $key->review_after->isPast(),
                'fingerprint' => substr(hash('sha256', (string) $key->public_key), 0, 16),
            ];
        }

        $sync = $this->syncState($server);
        $drift = $this->driftState($server);

        $alerts = array_merge(
            $this->syncAlerts($sync, $server),
            $this->reviewAlerts($reviewOverdue),
            $this->driftAlerts($drift, $server),
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

        $activeSessions = $this->mapActiveSessions($context->activeSessions());
        $platformAccessRecent = $context->remoteAccessEvents
            ->filter(fn ($event) => $event->started_at !== null && $event->started_at->gte(now()->subDays(30)))
            ->count();

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'sync' => $sync,
            'drift' => $drift,
            'summary' => [
                'total' => count($rows),
                'by_source' => $bySource,
                'review_overdue' => $reviewOverdue,
                'never_synced' => $neverSynced,
                'active_sessions' => count($activeSessions),
                'platform_access_recent' => $platformAccessRecent,
            ],
            'sessions' => $activeSessions,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, ServerSshSession>  $sessions
     * @return list<array<string, mixed>>
     */
    private function mapActiveSessions($sessions): array
    {
        return $sessions
            ->map(fn (ServerSshSession $session): array => [
                'id' => (string) $session->id,
                'name' => (string) $session->name,
                'expires_at' => $session->expires_at,
                'created_by' => (string) ($session->createdBy->name ?? ''),
                'fingerprint' => substr((string) $session->public_key_fingerprint, -16),
            ])
            ->all();
    }

    private function sourceLabel(ServerAuthorizedKey $key): string
    {
        return match ($key->managed_key_type) {
            UserSshKey::class => 'profile',
            OrganizationSshKey::class => 'organization',
            TeamSshKey::class => 'team',
            SiteDeploymentEphemeralCredential::class => 'ephemeral',
            ServerSshSession::class => 'session',
            default => 'server-local',
        };
    }

    /**
     * @return array{disabled: bool, last_status: ?string, last_finished_at: ?Carbon}
     */
    private function syncState(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $disabled = (bool) data_get($meta, config('server_ssh_keys.meta_disable_sync_key'), false);
        $status = data_get($meta, config('server_ssh_keys.meta_sync_status_key'));
        $finished = data_get($meta, config('server_ssh_keys.meta_sync_finished_at_key'));

        return [
            'disabled' => $disabled,
            'last_status' => is_string($status) && $status !== '' ? $status : null,
            'last_finished_at' => $this->parseTime($finished),
        ];
    }

    /**
     * @return array{status: ?string, finished_at: ?Carbon}
     */
    private function driftState(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $status = data_get($meta, config('server_ssh_keys.meta_drift_status_key'));
        $finished = data_get($meta, config('server_ssh_keys.meta_drift_finished_at_key'));

        return [
            'status' => is_string($status) && $status !== '' ? $status : null,
            'finished_at' => $this->parseTime($finished),
        ];
    }

    /**
     * @param  array{disabled: bool, last_status: ?string, last_finished_at: ?Carbon}  $sync
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function syncAlerts(array $sync, Server $server): array
    {
        $alerts = [];
        $href = route('servers.ssh-keys', $server);

        if ($sync['disabled']) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('authorized_keys sync disabled'),
                'message' => __('Break-glass mode is on — panel changes will not write to the server until re-enabled.'),
                'href' => $href,
                'link_label' => __('Open SSH keys'),
            ];
        }

        if ($sync['last_status'] === 'failed') {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('Last key sync failed'),
                'message' => __('Review the sync output on the SSH keys workspace and retry.'),
                'href' => $href,
                'link_label' => __('Open SSH keys'),
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function reviewAlerts(int $reviewOverdue): array
    {
        if ($reviewOverdue === 0) {
            return [];
        }

        return [[
            'severity' => 'warning',
            'title' => trans_choice(':count key past review date|:count keys past review date', $reviewOverdue, ['count' => $reviewOverdue]),
            'message' => __('Rotate or remove contractor keys that exceeded their review-after date.'),
            'href' => null,
            'link_label' => null,
        ]];
    }

    /**
     * @param  array{status: ?string, finished_at: ?Carbon}  $drift
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function driftAlerts(array $drift, Server $server): array
    {
        if ($drift['status'] !== 'completed' || $drift['finished_at'] === null) {
            return [];
        }

        $staleHours = max(1, (int) config('server_ssh_access.stale_drift_hours', 24));
        if ($drift['finished_at']->lt(now()->subHours($staleHours))) {
            return [[
                'severity' => 'warning',
                'title' => __('Drift preview is stale'),
                'message' => __('Refresh the drift preview on SSH keys to compare panel vs on-disk authorized_keys.'),
                'href' => route('servers.ssh-keys', $server).'?tab=preview',
                'link_label' => __('Preview drift'),
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
