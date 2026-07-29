<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Events\BundleEntitlementChanged;
use App\Modules\Billing\Jobs\SendTracelyBundleWebhookJob;
use App\Modules\Billing\Services\LookoutBundleProvisioner;

/**
 * Fans a `bundle.*` transition out to both product transports (Q5, asymmetric):
 *  - tracely  → a signed, queued webhook ({@see SendTracelyBundleWebhookJob}).
 *  - Lookout  → in-process, reusing its existing provisioning path
 *               ({@see LookoutBundleProvisioner}).
 *
 * One monotonic ULID event id is minted per transition and used for both the
 * webhook's idempotency/ordering and cross-transport correlation.
 *
 * See docs/adr/bundled-products-sso.md.
 */
final class PropagateBundleEntitlement
{
    public function __construct(
        private readonly LookoutBundleProvisioner $lookout,
    ) {}

    public function handle(BundleEntitlementChanged $event): void
    {
        $eventId = SendTracelyBundleWebhookJob::idFor();

        SendTracelyBundleWebhookJob::dispatch(
            $event->organizationId,
            $event->transition,
            $eventId,
        );

        $this->lookout->apply($event->organizationId, $event->transition);
    }
}
