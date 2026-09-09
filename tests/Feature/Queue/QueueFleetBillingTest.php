<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueFleetBillingTest;

use App\Models\Organization;
use App\Modules\Billing\Services\QueueFleetUsageCostCalculator;
use App\Modules\Billing\Services\QueueFleetUsageReader;
use App\Modules\Queue\Models\QueueUsageDaily;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();

    config([
        'queue_service.billing.enabled' => true,
        'queue_service.fleets.pricing.flex_millicents_per_mib_second' => 0.0001,
        'queue_service.fleets.pricing.pro_multiplier' => 1.20,
        'queue_service.fleets.pricing.millicents_per_million_operations' => 100_000,
    ]);
});

function usageRow(array $attributes): QueueUsageDaily
{
    return QueueUsageDaily::query()->create(array_merge([
        'organization_id' => test()->org->id,
        'day' => now()->utc()->toDateString(),
        'source' => QueueUsageDaily::SOURCE_COUNTER,
        'jobs_pushed' => 0,
    ], $attributes));
}

function estimate(): array
{
    $reader = app(QueueFleetUsageReader::class);
    [$from, $to] = $reader->currentMonthWindow();

    return app(QueueFleetUsageCostCalculator::class)
        ->estimate($reader->totalsForOrganization(test()->org, $from, $to));
}

/** Dark by default: a product must not invoice before its rates are calibrated. */
test('nothing is charged while queue billing is off', function () {
    config(['queue_service.billing.enabled' => false]);
    usageRow(['flex_mib_seconds' => 10_000_000, 'operations' => 5_000_000]);

    $estimate = estimate();

    expect($estimate['subtotal_cents'])->toBe(0)
        ->and($estimate['enabled'])->toBeFalse()
        // Quantities still reported — you cannot calibrate against nothing.
        ->and($estimate['flex_mib_seconds'])->toBe(10_000_000);
});

test('worker time is charged per MiB-second', function () {
    // 10,000,000 MiB-s x 0.0001 millicents = 1,000 millicents = 1 cent.
    usageRow(['flex_mib_seconds' => 10_000_000]);

    expect(estimate()['worker_cents'])->toBe(1);
});

/** The premium is the only thing separating the two classes' prices. */
test('pro time carries its multiplier over the equivalent flex size', function () {
    usageRow(['pro_mib_seconds' => 10_000_000]);

    // 1 cent x 1.20, rounded up.
    expect(estimate()['worker_cents'])->toBe(2);
});

test('operations are charged per million', function () {
    usageRow(['operations' => 2_000_000]);

    // 2M ops x 100,000 millicents/M = 200,000 millicents = 200 cents.
    expect(estimate()['operations_cents'])->toBe(200)
        ->and(estimate()['worker_cents'])->toBe(0);
});

test('the subtotal is worker time plus operations', function () {
    usageRow(['flex_mib_seconds' => 10_000_000, 'operations' => 2_000_000]);

    $estimate = estimate();

    expect($estimate['subtotal_cents'])->toBe($estimate['worker_cents'] + $estimate['operations_cents'])
        ->and($estimate['subtotal_cents'])->toBe(201);
});

test('usage sums across every day in the month', function () {
    usageRow(['flex_mib_seconds' => 5_000_000, 'day' => now()->utc()->startOfMonth()->toDateString()]);
    usageRow(['flex_mib_seconds' => 5_000_000, 'source' => QueueUsageDaily::SOURCE_MANUAL]);

    expect(estimate()['flex_mib_seconds'])->toBe(10_000_000);
});

/** An org with no fleets must not acquire a queue line. */
test('an organization with no managed queue usage is charged nothing', function () {
    expect(estimate()['subtotal_cents'])->toBe(0);
});
