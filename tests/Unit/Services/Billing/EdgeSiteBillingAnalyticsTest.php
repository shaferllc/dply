<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge site billing includes platform fee and usage for active site', function () {
    config(['dply.edge.usage_billing.enabled' => true]);

    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);
    $site = Site::factory()->for($org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(5),
    ]);

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 50_000,
        'bytes_egress' => 512 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    $row = app(EdgeSiteBillingAnalytics::class)->forSite($site->fresh());

    expect($row)->not->toBeNull()
        ->and($row['platform_cents'])->toBe(200)
        ->and($row['requests'])->toBe(50_000)
        ->and($row['daily'])->not->toBeEmpty()
        ->and($row['total_cents'])->toBeGreaterThanOrEqual(200);
});

test('sites for organization lists all billable edge sites', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    Site::factory()->for($org)->for($server)->create([
        'name' => 'Marketing',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(3),
    ]);

    $sites = app(EdgeSiteBillingAnalytics::class)->sitesForOrganization($org);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]['site_name'])->toBe('Marketing')
        ->and($sites[0]['platform_cents'])->toBe(200);
});
