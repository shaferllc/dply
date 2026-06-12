<?php

namespace App\Support;

use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\RunWebserverConfigOpJob;
use App\Jobs\SwitchServerWebserverJob;

/**
 * Notification event keys for server-scoped webserver changes, surfaced on the
 * /servers/{server}/webserver workspace (engine switch, rollback, config-file
 * edits). The `server.` prefix maps these to the Server subscribable in
 * {@see NotificationSubscriptionRules::subscribableClassForEvent}; they are listed
 * under the "webserver" category in config/notification_events.php.
 *
 * Scoped to the headline mutations that flow through the queued-job chokepoints
 * ({@see SwitchServerWebserverJob}, {@see RevertServerWebserverSwitchJob},
 * {@see RunWebserverConfigOpJob}). Mirrors {@see ServerFirewallNotificationKeys}.
 */
final class ServerWebserverNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['engine_switched', 'engine_switch_failed', 'switch_reverted', 'config_saved'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid webserver notify kind.');
        }

        return 'server.webserver.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
