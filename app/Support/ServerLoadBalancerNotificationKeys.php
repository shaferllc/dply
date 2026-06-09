<?php

namespace App\Support;

/**
 * Notification event keys for server-scoped load-balancer changes, surfaced on the
 * /servers/{server}/load-balancers workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "load_balancer" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 *
 * Mirrors {@see ServerFirewallNotificationKeys}.
 */
final class ServerLoadBalancerNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'deleted', 'target_added', 'target_removed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid load balancer notify kind.');
        }

        return 'server.load_balancer.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
