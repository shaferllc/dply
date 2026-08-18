<?php

namespace App\Support;

use App\Modules\Logs\Jobs\EvaluateLogAlertJob;

/**
 * Notification event keys for dply Logs alerting, surfaced on the server and
 * site Logs workspaces. The `server.` prefix maps these to the Server
 * subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent}
 * and they are listed under the "logs" category in config/notification_events.php
 * so they appear in the bulk notification-assignment UI.
 *
 * `alert_triggered` fires from {@see EvaluateLogAlertJob} when a threshold or
 * pattern rule matches shipped logs. Stakeholders already get the in-app bell;
 * the Logs → Notifications tab routes the same event to email / Slack / webhook.
 */
final class ServerLogNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['alert_triggered'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid logs notify kind.');
        }

        return 'server.logs.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
