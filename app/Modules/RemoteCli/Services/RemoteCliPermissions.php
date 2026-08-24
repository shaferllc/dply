<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Services;

use App\Models\Site;
use App\Models\User;

/**
 * Two-tier permission gate for wp-cli / artisan invocations (Q17).
 *
 * - Read → {@see SitePolicy::view} (inventory / keys-only inspect).
 * - MutatingRecoverable → {@see SitePolicy::update}.
 * - Destructive → site update plus org admin/owner.
 *
 * Org membership is not site-update. Project viewers can view a
 * workspace site but must not run mutating wp-cli (user create,
 * plugin install, db query, …) or dump wp-config secrets unmasked.
 *
 * System-triggered runs (no user — e.g. scaffold pipeline applying a
 * hardening default) are always permitted; the audit log marks them
 * with transport='system' so they're distinguishable from user actions.
 */
class RemoteCliPermissions
{
    public function can(?User $user, Site $site, RiskLevel $risk): bool
    {
        // System-triggered runs bypass the gate. The pipeline that
        // dispatches them is itself trusted code; the audit log
        // identifies them.
        if ($user === null) {
            return true;
        }

        if (! $user->can('view', $site)) {
            return false;
        }

        if ($risk === RiskLevel::Read) {
            return true;
        }

        if (! $user->can('update', $site)) {
            return false;
        }

        if ($risk === RiskLevel::Destructive) {
            $org = $site->organization;

            return $org !== null && $org->hasAdminAccess($user);
        }

        return true;
    }

    /**
     * Convenience for assert-or-throw at service-call sites.
     */
    public function ensureCan(?User $user, Site $site, RiskLevel $risk, string $command): void
    {
        if (! $this->can($user, $site, $risk)) {
            throw new RemoteCliPermissionDeniedException($risk, $command);
        }
    }
}
