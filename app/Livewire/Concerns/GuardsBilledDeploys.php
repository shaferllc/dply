<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Site;

/**
 * Pre-gate human-initiated deploys when the owning org is pause-blocked from
 * billed work. Stops a click from dispatching RunSiteDeploymentJob (which would
 * otherwise persist a phantom "skipped" row that reads as a stuck deploy) and
 * surfaces the billing prompt instead. Components using this must also use
 * {@see DispatchesToastNotifications}.
 */
trait GuardsBilledDeploys
{
    /**
     * True when the org owning $site cannot run billed deploys right now
     * (expired trial / lapsed subscription). Null-safe: returns false when the
     * org can't be resolved, so this never blocks on missing data.
     */
    protected function deploysArePaused(?Site $site): bool
    {
        $organization = $site?->organization;

        return $organization !== null && ! $organization->canDeploy();
    }

    /**
     * Guard a human deploy action: when paused, surface the billing prompt and
     * return true so the caller can bail before dispatching any job. Returns
     * false (and does nothing) when deploys are permitted.
     */
    protected function blockedByDeployPause(?Site $site): bool
    {
        if (! $this->deploysArePaused($site)) {
            return false;
        }

        // A page-level <x-trial-pause-banner> already explains the state; the
        // toast is the immediate acknowledgement that the click was received
        // and intentionally not run. 'billing-paused' lets any listening modal
        // open if one is mounted.
        $this->dispatch('billing-paused');
        $this->toastError(__('Deploys are paused — add a payment method to resume.'));

        return true;
    }
}
