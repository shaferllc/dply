<?php

namespace App\Support;

use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;

/**
 * Notification event keys for server-scoped TLS certificate lifecycle, surfaced on
 * the /servers/{server}/cert-inventory workspace. The `server.` prefix maps these to
 * the Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "cert_inventory" category in config/notification_events.php.
 *
 * Fired from the certificate request job ({@see ExecuteSiteCertificateJob}),
 * which handles both first-issue and renewal of a site's certificate. Mirrors
 * {@see ServerWebserverNotificationKeys}.
 */
final class ServerCertInventoryNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['renewed', 'renewal_failed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid certificate notify kind.');
        }

        return 'server.cert.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
