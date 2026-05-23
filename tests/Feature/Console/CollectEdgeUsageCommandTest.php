<?php

declare(strict_types=1);

namespace Tests\Feature\Console\CollectEdgeUsageCommandTest;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('collect usage writes placeholder snapshots for active edge sites', function () {
    Config::set('edge.cloudflare.account_id', '');
    Config::set('edge.cloudflare.api_token', '');

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

    $date = now()->subDay()->toDateString();

    $this->artisan('dply:edge:collect-usage', ['--date' => $date])
        ->expectsOutputToContain('source=placeholder')
        ->assertOk();

    expect(EdgeUsageSnapshot::query()->where('site_id', $site->id)->count())->toBe(1);

    $snapshot = EdgeUsageSnapshot::query()->where('site_id', $site->id)->first();
    expect($snapshot->source)->toBe(EdgeUsageSnapshot::SOURCE_PLACEHOLDER);
    expect($snapshot->requests)->toBe(0);
});

test('dry run does not persist snapshots', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
    ]);

    $this->artisan('dply:edge:collect-usage', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertOk();

    expect(EdgeUsageSnapshot::query()->count())->toBe(0);
});
