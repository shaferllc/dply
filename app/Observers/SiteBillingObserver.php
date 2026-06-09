<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Site;

/**
 * Dispatch billing sync when managed-product sites enter or leave billable status.
 */
class SiteBillingObserver
{
    /**
     * @var list<string>
     */
    private const BILLABLE_STATUSES = [
        Site::STATUS_FUNCTIONS_ACTIVE,
        Site::STATUS_CONTAINER_ACTIVE,
        Site::STATUS_EDGE_ACTIVE,
    ];

    public function updated(Site $site): void
    {
        if (! $site->wasChanged('status')) {
            return;
        }

        $original = $site->getOriginal('status');
        $current = $site->status;

        if ($this->isBillableStatus($original) || $this->isBillableStatus($current)) {
            $this->dispatchBillingSync($site->organization_id);
        }
    }

    public function deleted(Site $site): void
    {
        if ($this->isBillableStatus($site->status)) {
            $this->dispatchBillingSync($site->organization_id);
        }
    }

    private function isBillableStatus(?string $status): bool
    {
        return is_string($status) && in_array($status, self::BILLABLE_STATUSES, true);
    }

    private function dispatchBillingSync(?string $organizationId): void
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organizationId, 'site_lifecycle');
    }
}
