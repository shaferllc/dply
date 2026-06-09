<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EdgeAccessLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge logpush ingest imports mapped access logs', function () {
    config([
        'edge.logpush.enabled' => true,
        'edge.logpush.secret' => 'logpush-secret',
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
        'meta' => ['edge' => ['routing' => ['hostname' => 'demo.on-dply.site']]],
    ]);

    $records = [[
        'ClientRequestHost' => 'demo.on-dply.site',
        'ClientRequestMethod' => 'GET',
        'ClientRequestURI' => '/',
        'EdgeResponseStatus' => 200,
        'EdgeResponseBytes' => 2048,
        'EdgeTimeToFirstByteMs' => 55,
        'ClientCountry' => 'US',
        'CacheCacheStatus' => 'hit',
        'EdgeStartTimestamp' => now()->toIso8601String(),
    ]];

    $this->postJson(route('hooks.edge.logpush'), $records, [
        'Authorization' => 'Bearer logpush-secret',
    ])->assertAccepted()
        ->assertJsonPath('imported', 1);

    $log = EdgeAccessLog::query()->where('site_id', $site->id)->first();
    expect($log)->not->toBeNull();
    expect($log->source)->toBe('logpush');
    expect($log->bytes_egress)->toBe(2048);
});

test('edge logpush ingest rejects unauthorized requests', function () {
    config(['edge.logpush.enabled' => true, 'edge.logpush.secret' => 'logpush-secret']);

    $this->postJson(route('hooks.edge.logpush'), [], [
        'Authorization' => 'Bearer wrong',
    ])->assertUnauthorized();
});
