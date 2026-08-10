<?php

declare(strict_types=1);

namespace App\Modules\Queue\Observers;

use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Queue\Models\QueueNamespace;

/**
 * Dispatch a billing sync when a queue namespace enters or leaves billable
 * status, or moves between capacity tiers. Billable namespaces are billed
 * per-tier (see {@see OrganizationBillingStateComputer}); without this the
 * Stripe subscription would only reconcile on the nightly sweep, so a new
 * namespace or a tier upgrade wouldn't bill until the next day.
 *
 * Mirrors {@see \App\Modules\Realtime\Observers\RealtimeAppBillingObserver},
 * with one addition it does not need: `site_id`. Because billability is derived
 * from the attached site rather than stamped on the row
 * ({@see QueueNamespace::isBillable()}), detaching or re-pointing a namespace
 * changes its price without touching status or tier.
 *
 * What this observer CANNOT catch is the other half of that: a namespace whose
 * price changed because the *site* changed backend underneath it. No write
 * happens on the namespace at all in that case, so there is nothing to observe.
 * That transition is picked up by the billing pass diffing against the previous
 * snapshot — see the flip notifier. This observer is the fast path; the diff is
 * the correctness backstop.
 */
class QueueNamespaceBillingObserver
{
    public function created(QueueNamespace $namespace): void
    {
        // Provisioning namespaces aren't billed yet, but a sync here is harmless
        // and covers a namespace seeded directly into an active state.
        if ($namespace->status === QueueNamespace::STATUS_ACTIVE) {
            $this->dispatchBillingSync($namespace->organization_id);
        }
    }

    public function updated(QueueNamespace $namespace): void
    {
        // A status flip into/out of active changes the billable count; a tier
        // change moves the line to a different price; a site_id change can move
        // the namespace between free and billed. Any of the three, resync.
        if ($namespace->wasChanged('status') || $namespace->wasChanged('tier') || $namespace->wasChanged('site_id')) {
            $original = $namespace->getOriginal('status');

            if ($this->isActive($original)
                || $this->isActive($namespace->status)
                || $namespace->wasChanged('tier')
                || $namespace->wasChanged('site_id')
            ) {
                $this->dispatchBillingSync($namespace->organization_id);
            }
        }
    }

    public function deleted(QueueNamespace $namespace): void
    {
        if ($this->isActive($namespace->status)) {
            $this->dispatchBillingSync($namespace->organization_id);
        }
    }

    private function isActive(?string $status): bool
    {
        return $status === QueueNamespace::STATUS_ACTIVE;
    }

    private function dispatchBillingSync(?string $organizationId): void
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organizationId, 'queue_lifecycle');
    }
}
