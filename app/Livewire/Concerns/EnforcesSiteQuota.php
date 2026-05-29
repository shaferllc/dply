<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Support\NotificationToastPosition;

/**
 * Shared site-quota guard for site-creation Livewire components. A create
 * action calls this first and bails out with a friendly upgrade toast when the
 * organization has reached its plan's site ceiling — the user-facing hard block.
 */
trait EnforcesSiteQuota
{
    /**
     * Returns true (after dispatching an error toast) when the organization
     * has hit its plan's site ceiling, so the caller can abort gracefully.
     */
    protected function siteQuotaReached(?Organization $organization): bool
    {
        if ($organization === null || $organization->canCreateSite()) {
            return false;
        }

        $this->dispatch(
            'notify',
            message: $organization->siteLimitMessage(),
            type: 'error',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );

        return true;
    }
}
