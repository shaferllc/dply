<?php

declare(strict_types=1);

use App\Jobs\ExecuteSiteCertificateJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteWebserverConfigApplier;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('site provision begin uses edge backend applier when edge proxy is active', function (): void {
    Queue::fake();
    $server = Server::factory()->create([
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'meta' => ['edge_proxy' => 'envoy', 'webserver' => 'nginx'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'document_root' => '/var/www/demo/public',
    ]);

    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'testing-a6a4129f.ondply.io',
        'is_primary' => true,
    ]);

    $testingMock = Mockery::mock(TestingHostnameProvisioner::class);
    $testingMock->shouldReceive('provision')
        ->once()
        ->andReturnUsing(function (Site $provisioned): void {
            $provisioned->forceFill([
                'meta' => array_merge(
                    is_array($provisioned->meta) ? $provisioned->meta : [],
                    [
                        'testing_hostname' => [
                            'status' => 'ready',
                            'hostname' => 'testing-a6a4129f.ondply.io',
                        ],
                    ],
                ),
            ])->save();
        });
    app()->instance(TestingHostnameProvisioner::class, $testingMock);

    $applierMock = Mockery::mock(SiteWebserverConfigApplier::class);
    $applierMock->shouldReceive('apply')
        ->once()
        ->andReturn('edge backend ok');
    app()->instance(SiteWebserverConfigApplier::class, $applierMock);

    app(SiteProvisioner::class)->begin($site->fresh(['server', 'previewDomains']));

    expect($site->fresh()->provisioningMeta()['state'] ?? null)->toBe('waiting_for_http');
    Queue::assertPushed(ExecuteSiteCertificateJob::class);
});
