<?php

namespace App\Support;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Servers\Deploys;

/**
 * Notification event keys for the server-wide deploy window policy, surfaced on
 * the Deploys page (servers.deploys?tab=deploy-windows). The `server.` prefix maps these
 * to the Server subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "deploy_window" category in config/notification_events.php.
 *
 * `deploy_blocked` fires from {@see RunSiteDeploymentJob} when a deploy is
 * skipped by an active deny window. `policy_enabled` / `policy_disabled` fire from
 * {@see Deploys::savePolicy} when an operator
 * flips enforcement. Mirrors {@see ServerCertInventoryNotificationKeys}.
 */
final class ServerDeployPolicyNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['deploy_blocked', 'policy_enabled', 'policy_disabled'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid deploy policy notify kind.');
        }

        return 'server.deploy_window.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }
}
