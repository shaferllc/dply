<?php

namespace App\Support;

use App\Services\Servers\ServerMaintenanceWindow;

/**
 * Notification event keys for server-scoped visitor maintenance windows, surfaced
 * on the /servers/{server}/maintenance workspace. The `server.` prefix maps these
 * to the Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "maintenance" category in config/notification_events.php.
 *
 * Fired from {@see ServerMaintenanceWindow} when a window is
 * enabled, ended manually, or auto-ended after its scheduled `until`. Mirrors
 * {@see ServerCertInventoryNotificationKeys}.
 */
final class ServerMaintenanceNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['enabled', 'disabled', 'auto_expired'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid maintenance notify kind.');
        }

        return 'server.maintenance.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
