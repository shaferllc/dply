<?php

declare(strict_types=1);

use App\Enums\BundleTransition;
use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use App\Modules\Billing\Events\BundleEntitlementChanged;
use App\Modules\Billing\Services\BundleEntitlementSynchronizer;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config()->set('bundle.enabled', true);
    Event::fake([BundleEntitlementChanged::class]);
});

/** A real org row wrapped so its entitlement predicate is controllable. */
function orgQualifying(Organization $org, bool $qualifies): Organization
{
    $mock = Mockery::mock($org)->makePartial();
    $mock->shouldReceive('qualifiesForBundledProducts')->andReturn($qualifies);

    return $mock;
}

it('is inert while the perk is dark', function (): void {
    config()->set('bundle.enabled', false);
    $org = Organization::factory()->create();

    expect(app(BundleEntitlementSynchronizer::class)->sync(orgQualifying($org, true)))->toBeNull();
    expect(OrganizationBundleEntitlement::count())->toBe(0);
    Event::assertNotDispatched(BundleEntitlementChanged::class);
});

it('walks provision → idempotent → suspend → resume', function (): void {
    $sync = app(BundleEntitlementSynchronizer::class);
    $org = Organization::factory()->create();

    expect($sync->sync(orgQualifying($org, true)))->toBe(BundleTransition::Provisioned);
    expect($sync->sync(orgQualifying($org, true)))->toBeNull(); // already active — no-op

    expect(OrganizationBundleEntitlement::where('organization_id', $org->id)->value('status'))
        ->toBe(OrganizationBundleEntitlement::STATUS_ACTIVE);

    expect($sync->sync(orgQualifying($org, false)))->toBe(BundleTransition::Suspended);
    expect($sync->sync(orgQualifying($org, false)))->toBeNull(); // already suspended — no-op
    expect($sync->sync(orgQualifying($org, true)))->toBe(BundleTransition::Resumed);

    Event::assertDispatchedTimes(BundleEntitlementChanged::class, 3);
});

it('purges only after the retention window', function (): void {
    config()->set('bundle.retention_days', 75);
    $sync = app(BundleEntitlementSynchronizer::class);
    $org = Organization::factory()->create();

    OrganizationBundleEntitlement::create([
        'organization_id' => $org->id,
        'status' => OrganizationBundleEntitlement::STATUS_SUSPENDED,
        'suspended_at' => now()->subDays(10),
    ]);
    expect($sync->purgeExpired($org))->toBeFalse(); // inside window

    OrganizationBundleEntitlement::where('organization_id', $org->id)->update(['suspended_at' => now()->subDays(90)]);
    expect($sync->purgeExpired($org))->toBeTrue(); // past window
    expect(OrganizationBundleEntitlement::where('organization_id', $org->id)->value('status'))
        ->toBe(OrganizationBundleEntitlement::STATUS_DELETED);
});
