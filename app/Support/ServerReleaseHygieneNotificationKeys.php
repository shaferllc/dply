<?php

namespace App\Support;

/**
 * Notification event keys for the server release hygiene workspace, surfaced on the
 * /servers/{server}/hygiene page. The `server.` prefix maps these to the Server
 * subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "release_hygiene" category in config/notification_events.php.
 *
 * Fired transition-aware from {@see \App\Services\Servers\ServerReleaseHygieneScanner}
 * after a scan (manual refresh or the daily {@see \App\Jobs\RunServerReleaseHygieneScanJob}):
 * `critical_finding` / `warning_finding` when release/disk/log pressure worsens into that
 * level, and `posture_cleared` when it recovers to healthy. Mirrors
 * {@see ServerSecurityDigestNotificationKeys}.
 */
final class ServerReleaseHygieneNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['critical_finding', 'warning_finding', 'posture_cleared'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid release hygiene notify kind.');
        }

        return 'server.release_hygiene.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
