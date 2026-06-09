<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEdgeBackendProvisioner;
use App\Services\Sites\SiteWebserverConfigApplier;
use Mockery;

test('webserver config applier uses edge backend provisioner when envoy is active', function (): void {
    $server = Server::factory()->make([
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'meta' => ['edge_proxy' => 'envoy', 'webserver' => 'caddy'],
    ]);
    $server->id = '01HZYTESTENVOYAPPLIER01';

    $site = Site::factory()->make([
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'document_root' => '/var/www/demo/public',
    ]);
    $site->id = '01HZYTESTENVOYAPPLIER02';
    $site->setRelation('server', $server);

    $mock = Mockery::mock(SiteEdgeBackendProvisioner::class)->makePartial();
    $mock->shouldReceive('provision')
        ->once()
        ->andReturn('ok');
    app()->instance(SiteEdgeBackendProvisioner::class, $mock);

    $result = app(SiteWebserverConfigApplier::class)->apply($site);

    expect($result)->toBe('ok');
});
