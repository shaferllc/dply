<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Edge\EdgeGuardrailStatus;
use App\Services\Edge\EdgeUsageGuardrail;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.guardrail.requests_per_month' => 1_000_000,
        'edge.guardrail.bytes_per_month' => 50 * 1024 * 1024 * 1024,
        'edge.guardrail.warn_at_percent' => 80,
    ]);
});

function makeEdgeSite(): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);

    return Site::factory()->for($org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
    ]);
}

function seedSnapshot(Site $site, int $requests, int $bytes): void
{
    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => $requests,
        'bytes_egress' => $bytes,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);
}

test('returns ok when no snapshots exist', function () {
    $site = makeEdgeSite();

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);

    expect($status->state)->toBe(EdgeGuardrailStatus::STATE_OK)
        ->and($status->requests)->toBe(0)
        ->and($status->bytesEgress)->toBe(0)
        ->and($status->requestsPercent())->toBe(0);
});

test('returns ok when below the warn threshold', function () {
    $site = makeEdgeSite();
    seedSnapshot($site, requests: 500_000, bytes: 10 * 1024 * 1024 * 1024); // 50% req, 20% bytes

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);

    expect($status->state)->toBe(EdgeGuardrailStatus::STATE_OK)
        ->and($status->requestsPercent())->toBe(50)
        ->and($status->bytesPercent())->toBe(20);
});

test('warns when one metric crosses the warn threshold', function () {
    $site = makeEdgeSite();
    seedSnapshot($site, requests: 850_000, bytes: 1 * 1024 * 1024 * 1024); // 85% req, ~2% bytes

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);

    expect($status->state)->toBe(EdgeGuardrailStatus::STATE_WARN)
        ->and($status->requestsPercent())->toBe(85);
});

test('marks over when any metric crosses 100%', function () {
    $site = makeEdgeSite();
    seedSnapshot($site, requests: 100, bytes: 60 * 1024 * 1024 * 1024); // 120% bytes

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);

    expect($status->state)->toBe(EdgeGuardrailStatus::STATE_OVER)
        ->and($status->bytesPercent())->toBe(120);
});

test('sums multiple snapshots within the current month', function () {
    $site = makeEdgeSite();
    seedSnapshot($site, requests: 400_000, bytes: 0);
    seedSnapshot($site, requests: 500_000, bytes: 0); // total 900k → warn

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);

    expect($status->requests)->toBe(900_000)
        ->and($status->state)->toBe(EdgeGuardrailStatus::STATE_WARN);
});

test('meta() shape is JSON-serializable and round-trips state', function () {
    $site = makeEdgeSite();
    seedSnapshot($site, requests: 850_000, bytes: 0);

    $status = app(EdgeUsageGuardrail::class)->evaluate($site);
    $meta = $status->meta();

    expect($meta)
        ->toHaveKeys(['state', 'requests', 'bytes_egress', 'requests_cap', 'bytes_egress_cap', 'requests_percent', 'bytes_percent', 'warn_at_percent', 'evaluated_at', 'period_start', 'period_end'])
        ->and($meta['state'])->toBe(EdgeGuardrailStatus::STATE_WARN);

    expect(json_encode($meta))->toBeString()->not->toBe(false);
});
