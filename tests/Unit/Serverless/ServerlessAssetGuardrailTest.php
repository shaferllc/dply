<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetGuardrailTest;

use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetGuardrail;
use App\Modules\Serverless\Services\ServerlessAssetGuardrailStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'dply.serverless.usage_billing.included_asset_storage_gb_per_function' => 1,
        'dply.serverless.usage_billing.included_asset_egress_gb_per_function' => 100,
        'serverless.assets.warn_at_percent' => 80,
    ]);
});

function snapshot(Site $site, string $day, int $storageBytes, int $egressBytes): void
{
    ServerlessUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => $day,
        'period_end' => $day,
        'source' => ServerlessUsageSnapshot::SOURCE_FUNCTION_INVOCATIONS,
        'asset_storage_bytes' => $storageBytes,
        'asset_bytes_egress' => $egressBytes,
    ]);
}

test('a site inside its allowance is ok', function () {
    $site = Site::factory()->create();
    snapshot($site, now()->startOfMonth()->toDateString(), 8 * 1024 ** 2, 1024 ** 3);

    expect(app(ServerlessAssetGuardrail::class)->evaluate($site)->state)
        ->toBe(ServerlessAssetGuardrailStatus::STATE_OK);
});

test('it warns past the threshold before anything is billed', function () {
    $site = Site::factory()->create();
    snapshot($site, now()->startOfMonth()->toDateString(), 0, 85 * 1024 ** 3);

    $status = app(ServerlessAssetGuardrail::class)->evaluate($site);

    expect($status->state)->toBe(ServerlessAssetGuardrailStatus::STATE_WARN);
    expect($status->egressPercent())->toBe(85);
});

test('it reports over once the allowance is exceeded', function () {
    $site = Site::factory()->create();
    snapshot($site, now()->startOfMonth()->toDateString(), 2 * 1024 ** 3, 0);

    expect(app(ServerlessAssetGuardrail::class)->evaluate($site)->state)
        ->toBe(ServerlessAssetGuardrailStatus::STATE_OVER);
});

/**
 * Storage is a level, not a flow. Summing daily rows would multiply a steady
 * 800 MB by the number of days and trip the guardrail on a site that never
 * grew.
 */
test('storage is averaged across days rather than summed', function () {
    $site = Site::factory()->create();
    $start = now()->startOfMonth();

    foreach (range(0, 4) as $offset) {
        snapshot($site, $start->copy()->addDays($offset)->toDateString(), 800 * 1024 ** 2, 0);
    }

    $status = app(ServerlessAssetGuardrail::class)->evaluate($site);

    expect($status->storageBytes)->toBe(800 * 1024 ** 2);
    expect($status->state)->toBe(ServerlessAssetGuardrailStatus::STATE_OK);
});

test('egress still sums across days', function () {
    $site = Site::factory()->create();
    $start = now()->startOfMonth();

    foreach (range(0, 4) as $offset) {
        snapshot($site, $start->copy()->addDays($offset)->toDateString(), 0, 30 * 1024 ** 3);
    }

    expect(app(ServerlessAssetGuardrail::class)->evaluate($site)->bytesEgress)
        ->toBe(150 * 1024 ** 3);
});

test('recording a guardrail returns the state it replaced so callers fire on transitions', function () {
    $site = Site::factory()->create();

    expect($site->updateServerlessAssetGuardrail(['state' => 'ok']))->toBeNull();
    expect($site->fresh()->updateServerlessAssetGuardrail(['state' => 'warn']))->toBe('ok');
    expect($site->fresh()->serverlessAssetGuardrail()['state'])->toBe('warn');
});
