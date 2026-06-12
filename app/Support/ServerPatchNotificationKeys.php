<?php

namespace App\Support;

use App\Jobs\ServerManageRemoteSshJob;

/**
 * Notification event keys for server-scoped OS patch / update actions, surfaced on
 * the /servers/{server}/patches workspace (apt upgrade / dist-upgrade, reboot,
 * unattended-upgrades toggle). The `server.` prefix maps these to the Server
 * subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "patches" category in config/notification_events.php.
 *
 * Distinct from the existing server-level `server.automatic_updates` alert (a
 * monitoring setting, not an action result). Fired from the shared manage-action
 * job ({@see ServerManageRemoteSshJob}) for patch task names only.
 * Mirrors {@see ServerWebserverNotificationKeys}.
 */
final class ServerPatchNotificationKeys
{
    /** @var list<string> */
    public const KINDS = [
        'updates_applied',
        'dist_upgrade_applied',
        'apply_failed',
        'reboot_completed',
        'auto_updates_enabled',
        'auto_updates_disabled',
    ];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid patch notify kind.');
        }

        return 'server.patches.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
