<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\RealtimeApp;
use App\Services\Billing\OrganizationBillingStateComputer;

/**
 * Dispatch a billing sync when a managed realtime app enters or leaves billable
 * status, or moves between connection tiers. Active apps are billed per-tier
 * (see {@see OrganizationBillingStateComputer}); without
 * this the Stripe subscription only reconciled on the nightly sweep, so a brand
 * new app or a tier upgrade wouldn't bill until the next day.
 */
class RealtimeAppBillingObserver
{
    public function created(RealtimeApp $app): void
    {
        // Provisioning apps aren't billed yet, but a sync here is harmless and
        // covers the case where an app is seeded directly into an active state.
        if ($app->status === RealtimeApp::STATUS_ACTIVE) {
            $this->dispatchBillingSync($app->organization_id);
        }
    }

    public function updated(RealtimeApp $app): void
    {
        // A status flip into/out of active changes the billable app count; a tier
        // change moves the line to a different price. Either way, resync.
        if ($app->wasChanged('status') || $app->wasChanged('tier')) {
            $original = $app->getOriginal('status');

            if ($this->isActive($original) || $this->isActive($app->status) || $app->wasChanged('tier')) {
                $this->dispatchBillingSync($app->organization_id);
            }
        }
    }

    public function deleted(RealtimeApp $app): void
    {
        if ($this->isActive($app->status)) {
            $this->dispatchBillingSync($app->organization_id);
        }
    }

    private function isActive(?string $status): bool
    {
        return $status === RealtimeApp::STATUS_ACTIVE;
    }

    private function dispatchBillingSync(?string $organizationId): void
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organizationId, 'realtime_lifecycle');
    }
}
