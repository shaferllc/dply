<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Enums\ServerTier;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerCostCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cost card totals provider note and dply tier fee', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'cost_monthly_note' => '$12/mo',
        ],
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_count' => 4, 'mem_total_kb' => 8 * 1024 * 1024, 'cpu_pct' => 10, 'mem_pct' => 12],
    ]);

    $report = app(ServerCostCard::class)->forServer($server->fresh());

    expect($report['provider']['source'])->toBe('note')
        ->and($report['provider']['monthly_usd_cents'])->toBe(1200)
        ->and($report['dply']['tier'])->toBe(ServerTier::M->value)
        ->and($report['sites']['count'])->toBe(1)
        ->and($report['totals']['monthly_usd_cents'])->toBe(1200 + ServerTier::M->priceCents())
        ->and($report['nudge']['kind'] ?? null)->toBe('oversized')
        ->and($report['overall'])->toBe('info')
        ->and($report['alert_count'])->toBeGreaterThan(0)
        ->and($report['site_rows'])->toHaveCount(1)
        ->and($report['summary']['per_site_cents'])->toBe($report['totals']['monthly_usd_cents']);
});

test('cost card flags constrained utilization nudge', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm', 'cost_monthly_note' => '10 USD'],
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_pct' => 92, 'mem_pct' => 70],
    ]);

    $report = app(ServerCostCard::class)->forServer($server->fresh());

    expect($report['nudge']['kind'] ?? null)->toBe('constrained')
        ->and($report['nudge']['severity'] ?? null)->toBe('warning')
        ->and($report['overall'])->toBe('critical');
});

test('cost card flags unknown provider as warning overall', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $report = app(ServerCostCard::class)->forServer($server->fresh());

    expect($report['overall'])->toBe('warning')
        ->and(collect($report['alerts'])->pluck('title')->contains(__('Provider cost unknown')))->toBeTrue();
});
