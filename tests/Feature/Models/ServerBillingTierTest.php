<?php

namespace Tests\Feature\Models\ServerBillingTierTest;

use App\Enums\ServerTier;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns xs when no metrics have been collected', function () {
    $server = Server::factory()->create();

    expect($server->billingTier())->toBe(ServerTier::XS);
});

test('classifies from latest snapshot', function () {
    $server = Server::factory()->create();

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => '2026-05-01T00:00:00Z',
        'payload' => [
            'cpu_count' => 4,
            'mem_total_kb' => 8 * 1024 * 1024,
        ],
    ]);

    expect($server->billingTier())->toBe(ServerTier::M);
});

test('uses most recent snapshot when multiple exist', function () {
    $server = Server::factory()->create();

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => '2026-05-01T00:00:00Z',
        'payload' => ['cpu_count' => 1, 'mem_total_kb' => 1024 * 1024],
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => '2026-05-02T00:00:00Z',
        'payload' => ['cpu_count' => 8, 'mem_total_kb' => 16 * 1024 * 1024],
    ]);

    expect($server->billingTier())->toBe(ServerTier::L);
});

test('falls back to xs when payload missing spec fields', function () {
    $server = Server::factory()->create();

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => '2026-05-01T00:00:00Z',
        'payload' => ['cpu_pct' => 12.0],
    ]);

    expect($server->billingTier())->toBe(ServerTier::XS);
});
