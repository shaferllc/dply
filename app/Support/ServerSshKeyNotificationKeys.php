<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped authorized-key CRUD, surfaced on the
 * /servers/{server}/ssh-keys workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "ssh_key" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 *
 * Mirrors {@see ServerSystemUserNotificationKeys}. Only created/removed — a key's
 * review-date tweak is internal metadata, not a change to what's authorized.
 */
final class ServerSshKeyNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'removed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid SSH key notify kind.');
        }

        return 'server.ssh_key.'.$kind;
    }

    /**
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
            'created' => __('Added'),
            'removed' => __('Removed'),
        ];
    }
}
