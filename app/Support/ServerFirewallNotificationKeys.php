<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped firewall changes, surfaced on the
 * /servers/{server}/firewall workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "firewall_rule" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 *
 * Mirrors {@see ServerSshKeyNotificationKeys}. `applied` fires when the queued
 * {@see \App\Jobs\ApplyFirewallJob} reconciles UFW on the host.
 */
final class ServerFirewallNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'updated', 'deleted', 'applied'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid firewall notify kind.');
        }

        return 'server.firewall_rule.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
