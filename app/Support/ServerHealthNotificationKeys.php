<?php

namespace App\Support;

use App\Jobs\CheckServerHealthJob;
use App\Services\Servers\ServerHealthNotifier;

/**
 * Notification event keys for the server health cockpit, surfaced on the
 * /servers/{server}/health workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "health" category in config/notification_events.php.
 *
 * Fired transition-aware from {@see ServerHealthNotifier} when
 * {@see CheckServerHealthJob} runs (per-server, on the fleet health cadence):
 * `critical_finding` / `warning_finding` when the cockpit's overall posture worsens
 * into that level, and `posture_cleared` when it recovers to healthy. Mirrors
 * {@see ServerSecurityDigestNotificationKeys}.
 */
final class ServerHealthNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['critical_finding', 'warning_finding', 'posture_cleared'];

    /** Server meta key holding the last posture we notified on (avoids re-alert spam). */
    public const NOTIFIED_OVERALL_KEY = 'health_notified_overall';

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid health notify kind.');
        }

        return 'server.health.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
