<?php

namespace App\Support;

use App\Models\NotificationSubscription;
use App\Models\Server;

/**
 * Per-unit systemd notification subscription keys (server-scoped, {@see NotificationSubscription}).
 *
 * @phpstan-type SystemdNotifyKind 'stopped'|'started'|'restarted'|'state_changed'
 */
final class ServerSystemdServiceNotificationKeys
{
    /** @var list<SystemdNotifyKind> */
    public const KINDS = ['stopped', 'started', 'restarted', 'state_changed'];

    /**
     * Short slug for event_key segment (max length guarded for 80-char column).
     */
    public static function slugFromUnit(string $normalizedUnit): string
    {
        $base = preg_replace('/\.service$/i', '', $normalizedUnit) ?? '';
        $base = is_string($base) ? strtolower($base) : '';
        $slug = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'unit';
        }
        if (strlen($slug) > 35) {
            $slug = 'h'.substr(sha1($normalizedUnit), 0, 10);
        }

        return $slug;
    }

    /**
     * @param  SystemdNotifyKind  $kind
     */
    public static function eventKey(string $normalizedUnit, string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid systemd notify kind.');
        }

        $key = 'server.systemd.u.'.self::slugFromUnit($normalizedUnit).'.'.$kind;
        if (strlen($key) > 80) {
            throw new \InvalidArgumentException('Systemd notification event key exceeds column limit.');
        }

        return $key;
    }

    public static function isValidDynamicEventKey(string $eventKey): bool
    {
        return (bool) preg_match(
            '/^server\.systemd\.u\.[a-z0-9_]{1,50}\.(stopped|started|restarted|state_changed)$/',
            $eventKey
        );
    }

    /**
     * @return array<string, int> slug => number of subscription rows for any kind
     */
    public static function alertSubscriptionCountsBySlug(Server $server): array
    {
        $rows = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->where('event_key', 'like', 'server.systemd.u.%')
            ->pluck('event_key');

        $counts = [];
        foreach ($rows as $ek) {
            if (preg_match('/^server\.systemd\.u\.([a-z0-9_]+)\.(stopped|started|restarted|state_changed)$/', (string) $ek, $m)) {
                $slug = $m[1];
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Human labels for Services modal checkboxes.
     *
     * @return array<SystemdNotifyKind, string>
     */
    public static function kindLabels(): array
    {
        return [
            'stopped' => __('Stopped'),
            'started' => __('Started'),
            'restarted' => __('Restarted'),
            'state_changed' => __('State change'),
        ];
    }
}
