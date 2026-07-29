<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeProvisioningViewData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('provisioning error is omitted when it matches the deployment failure reason', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $message = 'Git clone failed after 3 attempts: destination path already exists';
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_FAILED,
        'meta' => [
            'edge' => [
                'last_error' => $message,
                'source' => ['repo' => 'acme/app', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
            ],
        ],
    ]);
    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_FAILED,
        'failure_reason' => $message,
        'failed_at' => now(),
        'storage_prefix' => 'edge/test/prefix',
    ]);

    $data = EdgeProvisioningViewData::for($server, $site->fresh());

    expect($data['edgeProvisioningError'])->toBeNull();
});

test('provisioning error surfaces when site meta differs from deployment failure', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_FAILED,
        'meta' => [
            'edge' => [
                'last_error' => 'R2 credentials missing',
                'source' => ['repo' => 'acme/app', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
            ],
        ],
    ]);
    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_FAILED,
        'failure_reason' => 'npm ci exploded',
        'failed_at' => now(),
        'storage_prefix' => 'edge/test/prefix-2',
    ]);

    $data = EdgeProvisioningViewData::for($server, $site->fresh());

    expect($data['edgeProvisioningError'])->toBe('R2 credentials missing');
});
