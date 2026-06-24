<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LookoutProject;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;

/**
 * Dispatch a billing sync when a managed Lookout project enters or leaves
 * billable status, or moves between tiers. Active projects are billed per-tier
 * (see {@see \App\Modules\Billing\Services\OrganizationBillingStateComputer}) —
 * without this the Stripe subscription would only reconcile on the nightly
 * sweep, so a brand-new project or a tier change wouldn't bill until the next
 * day. Mirrors {@see \App\Modules\Realtime\Observers\RealtimeAppBillingObserver}.
 */
class LookoutProjectBillingObserver
{
    public function created(LookoutProject $project): void
    {
        if ($project->status === LookoutProject::STATUS_ACTIVE) {
            $this->dispatchBillingSync($project->organization_id);
        }
    }

    public function updated(LookoutProject $project): void
    {
        if ($project->wasChanged('status') || $project->wasChanged('tier')) {
            $original = $project->getOriginal('status');

            if ($this->isActive($original) || $this->isActive($project->status) || $project->wasChanged('tier')) {
                $this->dispatchBillingSync($project->organization_id);
            }
        }
    }

    public function deleted(LookoutProject $project): void
    {
        if ($this->isActive($project->status)) {
            $this->dispatchBillingSync($project->organization_id);
        }
    }

    private function isActive(?string $status): bool
    {
        return $status === LookoutProject::STATUS_ACTIVE;
    }

    private function dispatchBillingSync(?string $organizationId): void
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organizationId, 'lookout_lifecycle');
    }
}
