<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Billing\EdgeSiteTrafficAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('traffic analytics computes rolling and peak stats from snapshots', function () {
    Carbon::setTestNow('2026-05-23 12:00:00');

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => [
                    'hostname' => 'demo-abc123.on-dply.site',
                    'custom_domains' => [
                        ['hostname' => 'www.example.com'],
                    ],
                ],
            ],
        ],
    ]);

    foreach ([
        ['2026-05-20', 100, 1000],
        ['2026-05-21', 250, 2000],
        ['2026-05-22', 50, 500],
    ] as [$date, $requests, $bytes]) {
        EdgeUsageSnapshot::query()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'period_start' => $date,
            'period_end' => $date,
            'requests' => $requests,
            'bytes_egress' => $bytes,
            'r2_storage_bytes' => 0,
            'r2_class_a_ops' => 0,
            'r2_class_b_ops' => 0,
            'source' => 'manual',
        ]);
    }

    $traffic = app(EdgeSiteTrafficAnalytics::class)->forSite($site);

    expect($traffic)->not->toBeNull();
    expect($traffic['requests_7d'])->toBe(400);
    expect($traffic['bytes_egress_7d'])->toBe(3500);
    expect($traffic['peak_day']['requests'])->toBe(250);
    expect($traffic['last_collected_date'])->toBe('2026-05-22');
    expect($traffic['tracked_hostnames'])->toBe(['demo-abc123.on-dply.site', 'www.example.com']);
});

test('traffic analytics returns byo cloudflare shape for org credential sites', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'org_cloudflare',
        'meta' => [
            'edge' => [
                'routing' => ['hostname' => 'byo.example.com'],
            ],
        ],
    ]);

    $traffic = app(EdgeSiteTrafficAnalytics::class)->forSite($site);

    expect($traffic)->not->toBeNull();
    expect($traffic['byo_cloudflare'])->toBeTrue();
    expect($traffic['tracked_hostnames'])->toBe(['byo.example.com']);
});
