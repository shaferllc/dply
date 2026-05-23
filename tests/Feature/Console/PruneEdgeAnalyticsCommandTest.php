<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\EdgeAccessLog;
use App\Models\EdgePerformanceHourly;
use App\Models\EdgeWebVital;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge analytics prune command removes old rows and enforces per-site caps', function () {
    config([
        'edge.analytics.access_logs_days' => 7,
        'edge.analytics.access_logs_keep_per_site' => 1,
        'edge.analytics.web_vitals_days' => 30,
        'edge.analytics.web_vitals_keep_per_site' => 1,
    ]);

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
    ]);

    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'demo.on-dply.site',
        'method' => 'GET',
        'path' => '/old',
        'occurred_at' => now()->subDays(10),
        'source' => 'worker',
    ]);
    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'demo.on-dply.site',
        'method' => 'GET',
        'path' => '/newer',
        'occurred_at' => now()->subMinute(),
        'source' => 'worker',
    ]);
    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'demo.on-dply.site',
        'method' => 'GET',
        'path' => '/newest',
        'occurred_at' => now(),
        'source' => 'worker',
    ]);

    EdgeWebVital::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'demo.on-dply.site',
        'path' => '/',
        'lcp_ms' => 1000,
        'occurred_at' => now()->subDays(40),
    ]);
    EdgeWebVital::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'demo.on-dply.site',
        'path' => '/',
        'lcp_ms' => 900,
        'occurred_at' => now(),
    ]);

    EdgePerformanceHourly::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hour_start' => now()->subDays(60)->startOfHour(),
        'source' => 'worker',
        'requests' => 1,
    ]);

    $this->artisan('dply:edge:prune-analytics')->assertOk();

    expect(EdgeAccessLog::query()->where('site_id', $site->id)->count())->toBe(1);
    expect(EdgeAccessLog::query()->where('site_id', $site->id)->value('path'))->toBe('/newest');
    expect(EdgeWebVital::query()->where('site_id', $site->id)->count())->toBe(1);
    expect(EdgePerformanceHourly::query()->where('site_id', $site->id)->count())->toBe(0);
});
