<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EdgeWebVital;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge vitals ingest records web vital with valid signature', function () {
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
        'path' => '/',
        'lcp_ms' => 2100,
        'cls' => 0.04,
        'inp_ms' => 120,
        'fcp_ms' => 900,
        'ttfb_ms' => 180,
        'country' => 'US',
        'occurred_at' => now()->toIso8601String(),
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $site->id.'.'.$body, 'test-ingest-key');

    $this->postJson(route('hooks.edge.vitals', $site), $payload, [
        'X-Dply-Signature' => $signature,
    ])->assertAccepted();

    expect(EdgeWebVital::query()->where('site_id', $site->id)->count())->toBe(1);
    $vital = EdgeWebVital::query()->where('site_id', $site->id)->first();
    expect($vital->lcp_ms)->toBe(2100);
    expect($vital->cls)->toBe(0.04);
});

test('edge vitals ingest rejects invalid signature', function () {
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

    $this->postJson(route('hooks.edge.vitals', $site), ['path' => '/'], [
        'X-Dply-Signature' => 'bad',
    ])->assertUnauthorized();
});
