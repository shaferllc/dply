<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logs\ServerLogEntitlementsTest;

use App\Models\Organization;
use App\Services\Logs\ServerLogEntitlement;
use App\Services\Logs\ServerLogEntitlements;
use Illuminate\Support\Facades\Config;
use Mockery;

/** @param array<string, mixed> $plan */
function orgOnPlan(array $plan): Organization
{
    $org = Mockery::mock(Organization::class)->makePartial();
    $org->shouldReceive('currentSubscriptionPlan')->andReturn($plan);

    return $org;
}

test('free plan resolves to the MVP defaults', function () {
    $ent = (new ServerLogEntitlements)->forOrganization(orgOnPlan(['key' => 'free']));

    expect($ent->planKey)->toBe('free');
    expect($ent->available)->toBeTrue();
    expect($ent->retentionDays)->toBe(7);
    expect($ent->monthlyIncludedGb)->toBe(1);
    expect($ent->overagePerGbCents)->toBe(0);
    expect($ent->alertingEnabled)->toBeFalse();
    expect($ent->drainsEnabled)->toBeFalse();
});

test('pro plan overrides retention + volume + feature flags', function () {
    $ent = (new ServerLogEntitlements)->forOrganization(orgOnPlan(['key' => 'pro']));

    expect($ent->planKey)->toBe('pro');
    expect($ent->available)->toBeTrue();
    expect($ent->retentionDays)->toBe(30);
    expect($ent->monthlyIncludedGb)->toBe(10);
    expect($ent->alertingEnabled)->toBeTrue();
    expect($ent->drainsEnabled)->toBeTrue();
});

test('unknown plan key falls back to defaults', function () {
    $ent = (new ServerLogEntitlements)->forOrganization(orgOnPlan(['key' => 'enterprise-custom']));

    expect($ent->retentionDays)->toBe(7);
    expect($ent->monthlyIncludedGb)->toBe(1);
});

test('availability is gateable per plan', function () {
    Config::set('server_logs.entitlements.plans.free', ['available' => false]);

    $ent = (new ServerLogEntitlements)->forOrganization(orgOnPlan(['key' => 'free']));

    expect($ent->available)->toBeFalse();
});

test('included-bytes math and over-included threshold', function () {
    $ent = ServerLogEntitlement::fromConfig('pro', ['monthly_included_gb' => 2]);

    expect($ent->includedBytes())->toBe(2 * 1073741824);
    expect($ent->isOverIncluded(1073741824))->toBeFalse();
    expect($ent->isOverIncluded(2 * 1073741824))->toBeFalse();
    expect($ent->isOverIncluded(2 * 1073741824 + 1))->toBeTrue();
});
