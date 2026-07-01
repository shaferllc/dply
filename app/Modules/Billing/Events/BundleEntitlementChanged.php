<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use App\Enums\BundleTransition;
use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an org's bundled-products entitlement transitions (provisioned /
 * suspended / resumed / deleted). ONE event, N transports: an in-process
 * listener drives Lookout via its existing provisioner, and a queued listener
 * POSTs a signed webhook to tracely. Both are idempotent and safe to replay.
 *
 * The event carries the org's ULID + transition only — listeners re-load the
 * org so a queued/replayed handler always sees fresh state (not a stale
 * serialized snapshot). See docs/adr/bundled-products-sso.md.
 */
final class BundleEntitlementChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly BundleTransition $transition,
    ) {}

    public static function for(Organization $organization, BundleTransition $transition): self
    {
        return new self((string) $organization->id, $transition);
    }
}
