<?php

namespace Tests\Feature\Observers\ServerObserverBillingSyncTest;

use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('dispatches sync when server transitions into ready', function () {
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
});

test('dispatches sync when server leaves ready', function () {
    Bus::fake();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    $server->update(['status' => Server::STATUS_DISCONNECTED]);

    Bus::assertDispatched(SyncOrganizationBillingJob::class);
});

test('dispatches sync when server is deleted', function () {
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
});

test('does not dispatch when status stays outside ready', function () {
    Bus::fake();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_PROVISIONING,
    ]);

    $server->update(['status' => Server::STATUS_ERROR]);

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

test('does not dispatch when unrelated field changes', function () {
    Bus::fake();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'name' => 'old-name',
    ]);

    $server->update(['name' => 'new-name']);

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});
