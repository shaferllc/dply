<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Enums\BundleTransition;
use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use App\Modules\Billing\Events\BundleEntitlementChanged;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles an org's persisted bundle state with the live
 * {@see Organization::qualifiesForBundledProducts()} predicate and emits the
 * resulting `bundle.*` transition (at most one per call). This is the ONE place
 * transitions originate — the Stripe-sync fast path, the nightly reconcile, and
 * the backfill command all funnel through here, so provisioning can never drift
 * from entitlement.
 *
 * Idempotent by construction: re-running against an already-consistent org is a
 * no-op (returns null, emits nothing). Row access is row-locked inside a
 * transaction so two concurrent billing syncs can't double-emit.
 *
 * DARK until config('bundle.enabled'). The `Deleted` (retention purge)
 * transition is NOT emitted here — it's driven by the scheduled purge command
 * once a suspension ages past config('bundle.retention_days').
 *
 * See docs/adr/bundled-products-sso.md.
 */
final class BundleEntitlementSynchronizer
{
    public function sync(Organization $organization): ?BundleTransition
    {
        if (! config('bundle.enabled', false)) {
            return null;
        }

        $transition = DB::transaction(function () use ($organization): ?BundleTransition {
            $row = OrganizationBundleEntitlement::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            $transition = $this->decide($row?->status, $organization->qualifiesForBundledProducts());
            if ($transition === null) {
                return null;
            }

            $this->persist($organization, $row, $transition);

            return $transition;
        });

        // Dispatch AFTER commit so a listener (in-process Lookout / tracely
        // webhook job) never observes state the transaction later rolls back.
        if ($transition !== null) {
            event(BundleEntitlementChanged::for($organization, $transition));
        }

        return $transition;
    }

    /**
     * Hard-purge an org whose suspension has aged past the retention window,
     * emitting the terminal `Deleted` transition. Driven by the scheduled purge
     * command, kept separate from {@see sync()} because it's time-based, not
     * entitlement-based. Returns true when a purge was emitted. No-op (and never
     * resurrects) an org that has re-qualified — sync() will have flipped it back
     * to active first.
     */
    public function purgeExpired(Organization $organization): bool
    {
        if (! config('bundle.enabled', false)) {
            return false;
        }

        $retentionDays = max(0, (int) config('bundle.retention_days', 75));

        return (bool) DB::transaction(function () use ($organization, $retentionDays): bool {
            $row = OrganizationBundleEntitlement::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($row === null
                || $row->status !== OrganizationBundleEntitlement::STATUS_SUSPENDED
                || $row->suspended_at === null
                || $row->suspended_at->gt(now()->subDays($retentionDays))) {
                return false;
            }

            $this->persist($organization, $row, BundleTransition::Deleted);
            event(BundleEntitlementChanged::for($organization, BundleTransition::Deleted));

            return true;
        });
    }

    /**
     * The state machine. `null`/`deleted` is "no live workspace"; `active` and
     * `suspended` mirror the downstream product status.
     */
    private function decide(?string $status, bool $qualifies): ?BundleTransition
    {
        if ($qualifies) {
            return match ($status) {
                null, OrganizationBundleEntitlement::STATUS_DELETED => BundleTransition::Provisioned,
                OrganizationBundleEntitlement::STATUS_SUSPENDED => BundleTransition::Resumed,
                default => null, // already active
            };
        }

        return $status === OrganizationBundleEntitlement::STATUS_ACTIVE
            ? BundleTransition::Suspended
            : null; // never provisioned, or already suspended/deleted
    }

    private function persist(Organization $organization, ?OrganizationBundleEntitlement $row, BundleTransition $transition): void
    {
        $row ??= new OrganizationBundleEntitlement(['organization_id' => (string) $organization->id]);

        match ($transition) {
            BundleTransition::Provisioned, BundleTransition::Resumed => $row->fill([
                'status' => OrganizationBundleEntitlement::STATUS_ACTIVE,
                'provisioned_at' => $row->provisioned_at ?? now(),
                'suspended_at' => null,
                'purged_at' => null,
            ]),
            BundleTransition::Suspended => $row->fill([
                'status' => OrganizationBundleEntitlement::STATUS_SUSPENDED,
                'suspended_at' => now(),
            ]),
            BundleTransition::Deleted => $row->fill([
                'status' => OrganizationBundleEntitlement::STATUS_DELETED,
                'purged_at' => now(),
            ]),
        };

        $row->save();
    }
}
