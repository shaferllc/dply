<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Support\NotificationToastPosition;

/**
 * Shared site-quota pre-check for site-creation Livewire components. Lets a
 * create action bail out with a friendly upgrade toast before it ever trips
 * the hard block in the Site model boundary.
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
