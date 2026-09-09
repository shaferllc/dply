<?php

namespace App\Livewire\Concerns;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Support\NotificationToastPosition;

/**
 * Shared quota guard for creation Livewire components. A create action calls
 * this first and bails out with a friendly upgrade toast when the organization
 * has reached the ceiling for the surface it is creating on — the user-facing
 * hard block.
 *
 * Each product surface has its own ceiling ({@see QuotaSurface}), so callers
 * must pass the surface they are creating on. It defaults to
 * {@see QuotaSurface::Site} because every existing caller is a machine-site
 * create path.
 */
trait EnforcesSiteQuota
{
    /**
     * Returns true (after dispatching an error toast) when the organization
     * has hit the ceiling for this surface, so the caller can abort gracefully.
     */
    protected function siteQuotaReached(?Organization $organization, QuotaSurface $surface = QuotaSurface::Site): bool
    {
        if ($organization === null || $organization->canCreateOnSurface($surface)) {
            return false;
        }

        $this->dispatch(
            'notify',
            message: $organization->quotaLimitMessage($surface),
            type: 'error',
            position: NotificationToastPosition::resolvedFor(auth()->user()),
        );

        return true;
    }
}
