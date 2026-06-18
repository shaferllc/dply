<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Services;

use App\Models\Site;
use App\Models\User;

/**
 * Two-tier permission gate for wp-cli / artisan invocations (Q17).
 *
 * - Read + MutatingRecoverable → any org member of the site's org.
 * - Destructive → admin or owner of the site's org.
 *
 * The gate is intentionally org-scoped (not site-scoped). Per-site
 * roles are deferred to v2; in v1 the assumption is that org membership
 * implies access to every site in that org. This matches how the rest
 * of the dply UI behaves today.
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

        $org = $site->organization;
        if ($org === null) {
            // Site without an org — legacy data, treat as locked down.
            // Read still allowed so dashboards don't blow up.
            return $risk === RiskLevel::Read;
        }

        if (! $org->hasMember($user)) {
            return false;
        }

        if ($risk === RiskLevel::Destructive) {
            return $org->hasAdminAccess($user);
        }

        // Read + MutatingRecoverable for any member.
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
