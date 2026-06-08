<?php

namespace App\Support;

/**
 * Notification event keys for the server security digest, surfaced on the
 * /servers/{server}/security-digest workspace. The `server.` prefix maps these to
 * the Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "security_digest" category in config/notification_events.php.
 *
 * Fired transition-aware from {@see \App\Services\Servers\ServerSecurityDigestScanner}
 * after a scan (manual refresh or the daily {@see \App\Jobs\RunServerSecurityDigestScanJob}):
 * `critical_finding` / `warning_finding` when posture worsens into that level, and
 * `posture_cleared` when it recovers to healthy. Mirrors {@see ServerCertInventoryNotificationKeys}.
 */
final class ServerSecurityDigestNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['critical_finding', 'warning_finding', 'posture_cleared'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid security digest notify kind.');
        }

        return 'server.security_digest.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
