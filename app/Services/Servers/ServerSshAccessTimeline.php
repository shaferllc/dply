<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerRemoteAccessEvent;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\ServerSshSession;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Support\Carbon;

/**
 * Builds time-series + swimlane data for the server access graph workspace.
 */
final class ServerSshAccessTimeline
{
    /**
     * @return array{
     *     range: string,
     *     from: Carbon,
     *     to: Carbon,
     *     series: list<array{at: int, total: float, you: float}>,
     *     lanes: list<array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon}>,
     *     events: list<array{at: Carbon, label: string, detail: string, is_you: bool}>,
     *     you_active_now: bool,
     * }
     */
    public function forServer(Server $server, ?User $viewer, string $range = '30d', ?ServerSshAccessContext $context = null): array
    {
        $context ??= ServerSshAccessContext::load($server);

        [$from, $to] = $this->resolveRange($range);
        $intervals = $this->collectIntervals($server, $viewer, $context);
        $events = $this->collectEvents($server, $viewer, $from, $context);

        $series = $this->buildSeries($intervals, $from, $to);
        $lanes = $this->buildLanes($intervals, $from, $to);

        $youActiveNow = collect($intervals)->contains(function (array $interval) use ($to): bool {
            if (! ($interval['is_you'] ?? false)) {
                return false;
            }

            return $interval['start']->lte($to) && $interval['end']->gte($to);
        });

        return [
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'series' => $series,
            'lanes' => $lanes,
            'events' => $events,
            'you_active_now' => $youActiveNow,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $range): array
    {
        $days = match ($range) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $to = now();
        $from = $to->copy()->subDays($days)->startOfDay();

        return [$from, $to];
    }

    /**
     * @return list<array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon}>
     */
    private function collectIntervals(Server $server, ?User $viewer, ServerSshAccessContext $context): array
    {
        $intervals = [];

        $keys = $context->authorizedKeys
            ->sortBy('created_at')
            ->values();

        foreach ($keys as $key) {
            $intervals[] = $this->intervalFromKey($key, $server, $viewer, now());
        }

        $createdAtByKeyId = [];
        $deletedAtByKeyId = [];
        $nameByKeyId = [];

        $context->auditEvents
            ->filter(fn (ServerSshKeyAuditEvent $event): bool => in_array($event->event, [
                ServerSshKeyAuditEvent::EVENT_KEY_CREATED,
                ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
            ], true))
            ->each(function (ServerSshKeyAuditEvent $event) use (&$createdAtByKeyId, &$deletedAtByKeyId, &$nameByKeyId): void {
                $keyId = (string) data_get($event->meta, 'authorized_key_id', '');
                if ($keyId === '') {
                    return;
                }

                if ($event->event === ServerSshKeyAuditEvent::EVENT_KEY_CREATED) {
                    $createdAtByKeyId[$keyId] = $event->created_at ?? now();
                    $nameByKeyId[$keyId] = (string) data_get($event->meta, 'name', __('Removed key'));
                }

                if ($event->event === ServerSshKeyAuditEvent::EVENT_KEY_DELETED) {
                    $deletedAtByKeyId[$keyId] = $event->created_at ?? now();
                }
            });

        $liveKeyIds = $keys->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($createdAtByKeyId as $keyId => $startedAt) {
            if (in_array($keyId, $liveKeyIds, true)) {
                continue;
            }

            $endedAt = $deletedAtByKeyId[$keyId] ?? $startedAt;

            $intervals[] = [
                'key' => 'historical-'.$keyId,
                'label' => $nameByKeyId[$keyId] ?? __('Removed key'),
                'source' => 'historical',
                'is_you' => false,
                'start' => $startedAt instanceof Carbon ? $startedAt : Carbon::parse($startedAt),
                'end' => $endedAt instanceof Carbon ? $endedAt : Carbon::parse($endedAt),
            ];
        }

        $context->sessions->each(function (ServerSshSession $session) use (&$intervals, $viewer): void {
            $end = $session->revoked_at ?? ($session->expires_at->isPast() ? $session->expires_at : now());
            if ($end->lt($session->provisioned_at)) {
                $end = $session->provisioned_at;
            }

            $intervals[] = [
                'key' => 'session-'.$session->id,
                'label' => (string) $session->name,
                'source' => 'session',
                'is_you' => $viewer !== null && (string) $session->created_by_user_id === (string) $viewer->id,
                'start' => $session->provisioned_at,
                'end' => $end,
            ];
        });

        $context->remoteAccessEvents->each(function (ServerRemoteAccessEvent $access) use (&$intervals): void {
            $end = $access->finished_at ?? ($access->isInFlight() ? now() : $access->started_at);
            if ($end->lt($access->started_at)) {
                $end = $access->started_at;
            }

            $intervals[] = [
                'key' => 'platform-'.$access->id,
                'label' => (string) $access->label,
                'source' => 'platform',
                'is_you' => false,
                'start' => $access->started_at,
                'end' => $end,
            ];
        });

        return $intervals;
    }

    /**
     * @return array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon}
     */
    private function intervalFromKey(
        ServerAuthorizedKey $key,
        Server $server,
        ?User $viewer,
        Carbon $end,
    ): array {
        $source = match ($key->managed_key_type) {
            UserSshKey::class => 'profile',
            ServerSshSession::class => 'session',
            SiteDeploymentEphemeralCredential::class => 'ephemeral',
            default => 'server-local',
        };

        $isYou = false;
        if ($viewer !== null && $key->managed_key_type === UserSshKey::class && $key->managedKey instanceof UserSshKey) {
            $isYou = (string) $key->managedKey->user_id === (string) $viewer->id;
        }

        if ($viewer !== null && $key->managed_key_type === ServerSshSession::class && $key->managedKey instanceof ServerSshSession) {
            $isYou = (string) $key->managedKey->created_by_user_id === (string) $viewer->id;
        }

        return [
            'key' => 'key-'.$key->id,
            'label' => (string) $key->name,
            'source' => $source,
            'is_you' => $isYou,
            'start' => $key->created_at ?? now(),
            'end' => $end,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon}>  $intervals
     * @return list<array{at: int, total: float, you: float}>
     */
    private function buildSeries(array $intervals, Carbon $from, Carbon $to): array
    {
        $bucketHours = max(1, (int) config('server_ssh_access.timeline_bucket_hours', 24));
        $points = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $sampleAt = $cursor->copy();
            $total = 0;
            $you = 0;

            foreach ($intervals as $interval) {
                if ($interval['start']->lte($sampleAt) && $interval['end']->gte($sampleAt)) {
                    $total++;
                    if ($interval['is_you']) {
                        $you++;
                    }
                }
            }

            $points[] = [
                'at' => $sampleAt->getTimestamp(),
                'total' => (float) $total,
                'you' => (float) $you,
            ];

            $cursor->addHours($bucketHours);
        }

        if ($points === []) {
            $points[] = ['at' => $to->getTimestamp(), 'total' => 0.0, 'you' => 0.0];
        }

        return $points;
    }

    /**
     * @param  list<array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon}>  $intervals
     * @return list<array{key: string, label: string, source: string, is_you: bool, start: Carbon, end: Carbon, left_pct: float, width_pct: float}>
     */
    private function buildLanes(array $intervals, Carbon $from, Carbon $to): array
    {
        $spanSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());

        $lanes = collect($intervals)
            ->map(function (array $interval) use ($from, $to, $spanSeconds): array {
                $start = $interval['start']->lt($from) ? $from->copy() : $interval['start']->copy();
                $end = $interval['end']->gt($to) ? $to->copy() : $interval['end']->copy();

                if ($end->lt($start)) {
                    return null;
                }

                $left = (($start->getTimestamp() - $from->getTimestamp()) / $spanSeconds) * 100;
                $width = max(0.6, (($end->getTimestamp() - $start->getTimestamp()) / $spanSeconds) * 100);

                return array_merge($interval, [
                    'start' => $start,
                    'end' => $end,
                    'left_pct' => round($left, 2),
                    'width_pct' => round(min(100 - $left, $width), 2),
                ]);
            })
            ->filter()
            ->sortByDesc(fn (array $lane) => [$lane['is_you'], $lane['start']->getTimestamp()])
            ->values()
            ->take((int) config('server_ssh_access.timeline_max_lanes', 24))
            ->all();

        return $lanes;
    }

    /**
     * @return list<array{at: Carbon, label: string, detail: string, is_you: bool}>
     */
    private function collectEvents(Server $server, ?User $viewer, Carbon $from, ServerSshAccessContext $context): array
    {
        $events = [];

        $context->auditEvents
            ->filter(fn (ServerSshKeyAuditEvent $event): bool => $event->created_at !== null && $event->created_at->gte($from))
            ->sortByDesc('created_at')
            ->take((int) config('server_ssh_access.timeline_max_events', 40))
            ->each(function (ServerSshKeyAuditEvent $event) use (&$events, $viewer): void {
                $label = match ($event->event) {
                    ServerSshKeyAuditEvent::EVENT_KEY_CREATED => __('Key added'),
                    ServerSshKeyAuditEvent::EVENT_KEY_DELETED => __('Key removed'),
                    ServerSshKeyAuditEvent::EVENT_KEY_UPDATED => __('Key updated'),
                    ServerSshKeyAuditEvent::EVENT_SYNC_COMPLETED => __('Keys synced'),
                    ServerSshKeyAuditEvent::EVENT_SYNC_BLOCKED => __('Sync blocked'),
                    ServerSshKeyAuditEvent::EVENT_ORG_KEY_DEPLOYED => __('Org key deployed'),
                    ServerSshKeyAuditEvent::EVENT_TEAM_KEY_DEPLOYED => __('Team key deployed'),
                    ServerSshKeyAuditEvent::EVENT_BULK_IMPORTED => __('Keys bulk-imported'),
                    ServerSshKeyAuditEvent::EVENT_SETTINGS_UPDATED => __('Settings updated'),
                    default => (string) $event->event,
                };

                $name = (string) data_get($event->meta, 'name', '');
                $actor = (string) ($event->user?->name ?? $event->user?->email ?? __('System'));
                $detail = $name !== '' ? $name.' · '.$actor : $actor;

                $events[] = [
                    'at' => $event->created_at ?? now(),
                    'label' => $label,
                    'detail' => $detail,
                    'is_you' => $viewer !== null && (string) $event->user_id === (string) $viewer->id,
                ];
            });

        $context->sessions
            ->filter(fn (ServerSshSession $session): bool => $session->provisioned_at->gte($from))
            ->sortByDesc('provisioned_at')
            ->take(20)
            ->each(function (ServerSshSession $session) use (&$events, $viewer, $from): void {
                $events[] = [
                    'at' => $session->provisioned_at,
                    'label' => __('Session granted'),
                    'detail' => $session->name.' · '.($session->createdBy?->name ?? __('Unknown')),
                    'is_you' => $viewer !== null && (string) $session->created_by_user_id === (string) $viewer->id,
                ];

                if ($session->revoked_at !== null && $session->revoked_at->gte($from)) {
                    $events[] = [
                        'at' => $session->revoked_at,
                        'label' => __('Session revoked'),
                        'detail' => $session->name,
                        'is_you' => $viewer !== null && (string) $session->created_by_user_id === (string) $viewer->id,
                    ];
                }
            });

        $context->remoteAccessEvents
            ->filter(fn (ServerRemoteAccessEvent $access): bool => $access->started_at->gte($from))
            ->take(20)
            ->each(function (ServerRemoteAccessEvent $access) use (&$events): void {
                $actor = (string) ($access->user?->name ?? $access->user?->email ?? __('Dply'));
                $detail = $access->label;
                if ($access->command_count > 0) {
                    $detail .= ' · '.trans_choice(':count command|:count commands', $access->command_count, ['count' => $access->command_count]);
                }
                if ($actor !== __('Dply')) {
                    $detail .= ' · '.$actor;
                }
                if ($access->failed) {
                    $detail .= ' · '.__('Failed');
                }

                $events[] = [
                    'at' => $access->started_at,
                    'label' => __('Dply platform access'),
                    'detail' => $detail,
                    'is_you' => false,
                    'source' => 'platform',
                ];
            });

        usort($events, fn (array $a, array $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($events, 0, (int) config('server_ssh_access.timeline_max_events', 40));
    }
}
