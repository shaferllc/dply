<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseCreatePageTest;

use App\Jobs\ProvisionCloudDatabaseJob;
use App\Livewire\Cloud\DatabaseCreate as CloudDatabaseCreate;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('surface.cloud');

test('page renders', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)->get(route('cloud.databases.create'))
        ->assertOk()
        ->assertSee('Create a managed database')
        ->assertSee('Engine')
        ->assertSee('Region')
        ->assertSee('Size');
});
test('page is gated by auth', function () {
    $this->get(route('cloud.databases.create'))->assertRedirect(route('login'));
});
test('page is gated by surface cloud feature', function () {
    Feature::define('surface.cloud', fn () => false);
    Feature::flushCache();
    $user = ownerWithOrg();

    // The feature:surface.cloud route middleware aborts before the
    // component mounts — Pennant's EnsureFeaturesAreActive uses 400.
    $this->actingAs($user)->get(route('cloud.databases.create'))->assertStatus(400);
});
test('page warns when no digitalocean credential', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)->get(route('cloud.databases.create'))
        ->assertSee('No DigitalOcean credential connected');
});
test('page hides warning when credential connected', function () {
    $user = ownerWithOrg();
    connectDoCredential($user);

    $this->actingAs($user)->get(route('cloud.databases.create'))
        ->assertDontSee('No DigitalOcean credential connected');
});
test('create validates required fields', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudDatabaseCreate::class)
        ->set('name', '')
        ->call('create')
        ->assertHasErrors(['name']);
});
test('changing engine resets version to newest supported', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudDatabaseCreate::class)
        ->set('engine', 'redis')
        ->assertSet('version', '7')
        ->set('engine', 'mysql')
        ->assertSet('version', '8');
});
test('create dispatches provision job and redirects', function () {
    Queue::fake();
    $user = ownerWithOrg();
    connectDoCredential($user);

    Livewire::actingAs($user)
        ->test(CloudDatabaseCreate::class)
        ->set('name', 'acme-primary')
        ->set('engine', 'postgres')
        ->set('version', '16')
        ->set('size', 'small')
        ->set('region', 'nyc1')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('cloud.databases.index'));

    Queue::assertPushed(ProvisionCloudDatabaseJob::class);

    $database = CloudDatabase::query()->where('name', 'acme-primary')->firstOrFail();
    expect($database->engine)->toBe('postgres');
    expect($database->version)->toBe('16');
    expect($database->size)->toBe('small');
    expect($database->region)->toBe('nyc1');
    expect($database->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    expect($database->organization_id)->toBe($user->currentOrganization()->id);
});
test('create without credential shows toast error', function () {
    Queue::fake();
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(CloudDatabaseCreate::class)
        ->set('name', 'lonely-db')
        ->set('engine', 'postgres')
        ->set('version', '16')
        ->set('size', 'small')
        ->set('region', 'nyc1')
        ->call('create')
        ->assertDispatched('notify');

    Queue::assertNotPushed(ProvisionCloudDatabaseJob::class);
    $this->assertDatabaseMissing('cloud_databases', ['name' => 'lonely-db']);
});
function connectDoCredential(User $user): void
{
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
}
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
