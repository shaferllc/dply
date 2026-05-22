<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StripeSubscriptionSyncer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciles an organization's Stripe subscription against its current
 * server fleet. Dispatched by lifecycle events (server created/ready/deleted,
 * tier changed) and by the nightly sweep command.
 *
 * Idempotent and uniquely-keyed per org so overlapping dispatches collapse.
 */
class SyncOrganizationBillingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public string $organizationId) {}

    public function uniqueId(): string
    {
        return $this->organizationId;
    }

    public function handle(
        OrganizationBillingStateComputer $computer,
        StripeSubscriptionSyncer $syncer,
    ): void {
        $organization = Organization::find($this->organizationId);
        if (! $organization) {
            return;
        }

        // Only sync orgs on the new Standard plan. Enterprise subs are managed
        // by hand in Stripe; legacy Pro subs are flat-fee and have no quantities.
        if (! $organization->onStandardSubscription()) {
            return;
        }

        $desired = $computer->compute($organization);
        $syncer->reconcile($organization, $desired);
    }
}
