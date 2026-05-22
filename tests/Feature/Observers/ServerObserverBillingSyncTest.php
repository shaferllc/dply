<?php

namespace Tests\Feature\Observers;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ServerObserverBillingSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_sync_when_server_transitions_into_ready(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_PROVISIONING,
        ]);

        $server->update(['status' => Server::STATUS_READY]);

        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
        );
    }

    public function test_dispatches_sync_when_server_leaves_ready(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
        ]);

        $server->update(['status' => Server::STATUS_DISCONNECTED]);

        Bus::assertDispatched(SyncOrganizationBillingJob::class);
    }

    public function test_dispatches_sync_when_server_is_deleted(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
        ]);

        $server->delete();

        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
        );
    }

    public function test_does_not_dispatch_when_status_stays_outside_ready(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_PROVISIONING,
        ]);

        $server->update(['status' => Server::STATUS_ERROR]);

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    public function test_does_not_dispatch_when_unrelated_field_changes(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'name' => 'old-name',
        ]);

        $server->update(['name' => 'new-name']);

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }
}
