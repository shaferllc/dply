<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseIndexPageTest;
use App\Jobs\TeardownCloudDatabaseJob;
use App\Livewire\Cloud\DatabaseIndex as CloudDatabaseIndex;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('page renders with empty state', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)->get(route('cloud.databases.index'))
        ->assertOk()
        ->assertSee('Managed databases')
        ->assertSee('No managed databases found');
});
test('page is gated by auth', function () {
    $this->get(route('cloud.databases.index'))->assertRedirect(route('login'));
});
test('page is gated by surface cloud feature', function () {
    \Laravel\Pennant\Feature::define('surface.cloud', fn () => false);
    \Laravel\Pennant\Feature::flushCache();
    $user = ownerWithOrg();

    // The feature:surface.cloud route middleware aborts before the
    // component mounts — Pennant's EnsureFeaturesAreActive uses 400.
    $this->actingAs($user)->get(route('cloud.databases.index'))->assertStatus(400);
});
test('lists only databases for current org', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $otherOrg = Organization::factory()->create();

    CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'mine-db']);
    CloudDatabase::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'other-org-db']);

    $this->actingAs($user)->get(route('cloud.databases.index'))
        ->assertOk()
        ->assertSee('mine-db')
        ->assertDontSee('other-org-db');
});
test('shows engine size region and status', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'live-pg',
        'engine' => CloudDatabase::ENGINE_POSTGRES,
        'size' => 'medium',
        'region' => 'ams3',
    ]);

    $this->actingAs($user)->get(route('cloud.databases.index'))
        ->assertSee('live-pg')
        ->assertSee('Postgres')
        ->assertSee('Medium')
        ->assertSee('ams3')
        ->assertSee('Active');
});
test('filter by engine', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    CloudDatabase::factory()->create(['organization_id' => $org->id, 'name' => 'pg-one']);
    CloudDatabase::factory()->redis()->create(['organization_id' => $org->id, 'name' => 'redis-one']);

    Livewire::actingAs($user)
        ->test(CloudDatabaseIndex::class)
        ->set('engine', 'redis')
        ->assertSee('redis-one')
        ->assertDontSee('pg-one');
});
test('filter by status', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    CloudDatabase::factory()->active()->create(['organization_id' => $org->id, 'name' => 'healthy-db']);
    CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'name' => 'failed-db',
        'status' => CloudDatabase::STATUS_FAILED,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseIndex::class)
        ->set('status', CloudDatabase::STATUS_FAILED)
        ->assertSee('failed-db')
        ->assertDontSee('healthy-db');
});
test('filter counts match actual databases', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    CloudDatabase::factory()->create(['organization_id' => $org->id]);
    CloudDatabase::factory()->redis()->create([
        'organization_id' => $org->id,
        'status' => CloudDatabase::STATUS_FAILED,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseIndex::class)
        ->assertViewHas('totals', fn ($t): bool => $t['all'] === 3
            && $t['postgres'] === 2
            && $t['redis'] === 1
            && $t['active'] === 1
            && $t['provisioning'] === 1
            && $t['failed'] === 1);
});
test('create button links to create page', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)->get(route('cloud.databases.index'))
        ->assertSee(route('cloud.databases.create'));
});
test('tear down dispatches job and marks deleting', function () {
    Queue::fake();
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseIndex::class)
        ->call('tearDown', $database->id);

    Queue::assertPushed(TeardownCloudDatabaseJob::class, fn (TeardownCloudDatabaseJob $job): bool => $job->cloudDatabaseId === $database->id);
    expect($database->fresh()->status)->toBe(CloudDatabase::STATUS_DELETING);
});
test('tear down ignores database from another org', function () {
    Queue::fake();
    $user = ownerWithOrg();
    $otherOrg = Organization::factory()->create();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $otherOrg->id]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseIndex::class)
        ->call('tearDown', $database->id)
        ->assertDispatched('notify');

    Queue::assertNotPushed(TeardownCloudDatabaseJob::class);
});
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
