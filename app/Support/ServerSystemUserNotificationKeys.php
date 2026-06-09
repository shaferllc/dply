<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped Linux account CRUD, surfaced on the
 * /servers/{server}/system-users workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "system_user" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 */
final class ServerSystemUserNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'updated', 'removed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid system user notify kind.');
        }

        return 'server.system_user.'.$kind;
    }

    /**
     * All system-user event keys, in CRUD order. Used to scope the in-page
     * Notifications tab on the system-users workspace and validate subscriptions.
     *
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            'created' => __('Created'),
            'updated' => __('Updated'),
            'removed' => __('Removed'),
        ];
    }
}
