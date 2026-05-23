<?php

declare(strict_types=1);

namespace Tests\Feature\Observers\SiteBillingObserverTest;

use App\Enums\SiteType;
use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('edge site going live dispatches billing sync', function () {
    Bus::fake([SyncOrganizationBillingJob::class]);
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
        'status' => Site::STATUS_EDGE_PROVISIONING,
    ]);

    $site->update(['status' => Site::STATUS_EDGE_ACTIVE]);

    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
    );
});

test('cloud site deletion dispatches billing sync when active', function () {
    Bus::fake([SyncOrganizationBillingJob::class]);
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'container_backend' => 'dply_cloud',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    $site->delete();

    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
    );
});
