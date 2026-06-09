<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped snapshot changes, surfaced on the
 * /servers/{server}/snapshots workspace (disk images, database snapshots, cache
 * RDB snapshots). The `server.` prefix maps these to the Server subscribable in
 * {@see NotificationSubscriptionRules::subscribableClassForEvent} and they are
 * listed under the "snapshot" category in config/notification_events.php so they
 * appear in the bulk notification-assignment UI.
 *
 * Mirrors {@see ServerFirewallNotificationKeys}. The snapshot type (image /
 * database / cache) rides in the notification metadata + label rather than in
 * separate event keys, keeping the subscribable surface compact.
 */
final class ServerSnapshotNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'restored', 'deleted'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid snapshot notify kind.');
        }

        return 'server.snapshot.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
