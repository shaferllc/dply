<?php

namespace App\Support;

/**
 * Notification event keys for a site's uptime monitoring, surfaced on the
 * /servers/{server}/sites/{site}/monitor page. The `site.` prefix maps these to
 * the Site subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "site_uptime" category in config/notification_events.php.
 *
 * Down/recovered share one key (`site.uptime.down`) — you can't sensibly
 * subscribe to outages without their recoveries. Degraded and SSL-expiry are
 * separate so a subscriber can opt into (or out of) the softer alerts.
 */
final class SiteUptimeNotificationKeys
{
    public const DOWN = 'site.uptime.down';

    public const DEGRADED = 'site.uptime.degraded';

    public const SSL_EXPIRING = 'site.ssl.expiring';

    /** @return list<string> */
    public static function eventKeys(): array
    {
        return [self::DOWN, self::DEGRADED, self::SSL_EXPIRING];
    }
}
