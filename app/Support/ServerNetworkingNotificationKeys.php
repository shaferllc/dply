<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped networking changes, surfaced on the
 * /servers/{server}/networking workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "networking" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 *
 * Mirrors {@see ServerSshKeyNotificationKeys}, but networking spans several distinct
 * operations, so each kind is its own event (database/cache exposure, private-network
 * attach/detach, route changes).
 */
final class ServerNetworkingNotificationKeys
{
    /** @var list<string> */
    public const KINDS = [
        'db_access_enabled',
        'db_access_disabled',
        'cache_exposed',
        'cache_locked_down',
        'network_created',
        'network_attached',
        'network_detached',
        'route_added',
        'route_removed',
    ];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid networking notify kind.');
        }

        return 'server.networking.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
