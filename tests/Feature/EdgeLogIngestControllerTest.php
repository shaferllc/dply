<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EdgeAccessLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge log ingest records access log with valid signature', function () {
    config(['edge.log_ingest.key' => 'test-ingest-key']);

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

    $payload = [
        'deployment_id' => '01TESTDEPLOYMENT000001',
        'hostname' => 'demo.on-dply.site',
        'method' => 'GET',
        'path' => '/',
        'status' => 200,
        'duration_ms' => 42,
        'bytes_egress' => 1024,
        'country' => 'US',
        'cache_status' => 'hit',
        'occurred_at' => now()->toIso8601String(),
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $site->id.'.'.$body, 'test-ingest-key');

    $this->postJson(route('hooks.edge.log', $site), $payload, [
        'X-Dply-Signature' => $signature,
    ])->assertAccepted();

    expect(EdgeAccessLog::query()->where('site_id', $site->id)->count())->toBe(1);
    $log = EdgeAccessLog::query()->where('site_id', $site->id)->first();
    expect($log->path)->toBe('/');
    expect($log->duration_ms)->toBe(42);
});

test('edge log ingest rejects invalid signature', function () {
    config(['edge.log_ingest.key' => 'test-ingest-key']);

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

    $this->postJson(route('hooks.edge.log', $site), ['path' => '/'], [
        'X-Dply-Signature' => 'bad',
    ])->assertUnauthorized();
});
