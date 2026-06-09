<?php

namespace App\Support;

/**
 * Notification event keys for the server error stream, surfaced on the
 * /servers/{server}/errors workspace. The `server.` prefix maps these to the
 * Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "errors" category in config/notification_events.php.
 *
 * Unlike the posture-rollup dispatchers (health / security digest), errors are
 * discrete: one notification per newly-captured {@see \App\Models\ErrorEvent}
 * row, fired from {@see \App\Support\Errors\ErrorEventSyncer} as the per-minute
 * sweep promotes a failed ConsoleAction / SiteDeployment into the stream. The
 * sweep only records each source once, so each error notifies at most once.
 *
 * Two kinds split the stream by what failed: `deploy_failed` for the `deploy`
 * category (a site deployment), `operation_failed` for everything else (SSL,
 * env sync, connectivity fixes, engine work…). Mirrors the shape of
 * {@see ServerHealthNotificationKeys}.
 */
final class ServerErrorsNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['deploy_failed', 'operation_failed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid errors notify kind.');
        }

        return 'server.errors.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }

    /** Map a captured error's category onto its notify kind. */
    public static function kindForCategory(string $category): string
    {
        return $category === 'deploy' ? 'deploy_failed' : 'operation_failed';
    }
}
