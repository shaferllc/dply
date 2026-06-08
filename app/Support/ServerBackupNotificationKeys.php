<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped backup changes, surfaced on the
 * /servers/{server}/backups workspace (database dumps + site file archives,
 * recurring schedules). The `server.` prefix maps these to the Server subscribable
 * in {@see NotificationSubscriptionRules::subscribableClassForEvent}; they are
 * listed under the "server_backup" category in config/notification_events.php.
 *
 * NOTE: deliberately distinct from the older site-scoped `backup.*` keys
 * (the `backup` category → Site subscribable). The backup type (database /
 * site_files) rides in the notification label + metadata, not in separate keys.
 *
 * Mirrors {@see ServerSnapshotNotificationKeys}.
 */
final class ServerBackupNotificationKeys
{
    /** @var list<string> */
    public const KINDS = [
        'run_started',
        'completed',
        'failed',
        'deleted',
        'schedule_created',
        'schedule_updated',
        'schedule_deleted',
    ];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid backup notify kind.');
        }

        return 'server.backup.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
