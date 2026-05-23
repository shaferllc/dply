<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\BillingSubscriptionSyncEvent;
use App\Models\Organization;
use App\Services\Billing\BillingSubscriptionSyncEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sync event recorder stores billing sync event payload', function () {
    $org = Organization::factory()->create();

    $event = app(BillingSubscriptionSyncEventRecorder::class)->record(
        organization: $org,
        trigger: 'nightly_sweep',
        status: BillingSubscriptionSyncEvent::STATUS_SUCCESS,
        changes: [['tier' => 'xs', 'action' => 'update', 'from' => 1, 'to' => 2]],
        desiredState: ['monthly_total_cents' => 1900],
        monthlyTotalCents: 1900,
    );

    expect($event->organization_id)->toBe($org->id)
        ->and($event->status)->toBe(BillingSubscriptionSyncEvent::STATUS_SUCCESS)
        ->and($event->changes)->toHaveCount(1)
        ->and($event->desired_state['monthly_total_cents'])->toBe(1900);
});
