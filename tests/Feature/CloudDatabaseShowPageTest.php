<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseShowPageTest;

use App\Livewire\Cloud\DatabaseShow as CloudDatabaseShow;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Database\Jobs\TeardownCloudDatabaseJob;
use App\Modules\Database\Jobs\ResizeManagedDatabaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('surface.cloud');

test('page renders the database overview', function () {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
        'name' => 'orders-pg',
        'region' => 'ams3',
    ]);

    $this->actingAs($user)->get(route('cloud.databases.show', $database))
        ->assertOk()
        ->assertSee('orders-pg')
        ->assertSee('Postgres')
        ->assertSee('ams3')
        ->assertSee('db.example.ondigitalocean.com')
        ->assertSee('doadmin');
});

test('the admin password is masked until revealed', function () {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->assertDontSee('secret-pass')
        ->set('revealPassword', true)
        ->assertSee('secret-pass');
});

test('every tab renders', function (string $tab) {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
    ]);

    // No provider credential on the row, so nothing here reaches the network —
    // this is a Blade-and-wiring smoke test for each panel.
    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->set('tab', $tab)
        ->assertOk();
})->with(['overview', 'sites', 'users', 'network', 'scale', 'metrics', 'backups', 'danger']);

test('page is gated by auth', function () {
    $database = CloudDatabase::factory()->create();

    $this->get(route('cloud.databases.show', $database))->assertRedirect(route('login'));
});

test('page is gated by surface cloud feature', function () {
    Feature::define('surface.cloud', fn () => false);
    Feature::flushCache();
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->create([
        'organization_id' => $user->currentOrganization()->id,
    ]);

    $this->actingAs($user)->get(route('cloud.databases.show', $database))->assertStatus(400);
});

test('another org database is not found', function () {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    $this->actingAs($user)->get(route('cloud.databases.show', $database))->assertNotFound();
});

test('an external database offers no users scale metrics or backups', function () {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
        'backend' => CloudDatabase::BACKEND_EXTERNAL,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->assertViewHas('capabilities', fn (array $c): bool => $c === array_fill_keys(
            ['users', 'resize', 'metrics', 'backups'],
            false,
        ));
});

test('a valkey cluster hides user management', function () {
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->redis()->create([
        'organization_id' => $user->currentOrganization()->id,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->set('tab', 'users')
        ->assertSee('Valkey authenticates with a single cluster credential');
});

test('scaling queues a resize with no binding', function () {
    Queue::fake();
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
        'size' => 'small',
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->set('targetSize', 'db-s-2vcpu-4gb')
        ->call('scale');

    Queue::assertPushed(
        ResizeManagedDatabaseJob::class,
        fn (ResizeManagedDatabaseJob $job): bool => $job->cloudDatabaseId === $database->id
            && $job->siteBindingId === null
            && $job->size === 'db-s-2vcpu-4gb',
    );

    expect($database->fresh()?->meta['resizing_to'] ?? null)->toBe('db-s-2vcpu-4gb');
});

test('scaling to the current plan is refused', function () {
    Queue::fake();
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
        'size' => 'small',
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->set('targetSize', $database->backendSizeSlug())
        ->call('scale');

    Queue::assertNothingPushed();
});

test('tear down queues the job and marks the row deleting', function () {
    Queue::fake();
    $user = ownerWithOrg();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $user->currentOrganization()->id,
    ]);

    Livewire::actingAs($user)
        ->test(CloudDatabaseShow::class, ['cloudDatabase' => $database])
        ->call('tearDown')
        ->assertRedirect(route('cloud.databases.index'));

    Queue::assertPushed(TeardownCloudDatabaseJob::class);
    expect($database->fresh()?->status)->toBe(CloudDatabase::STATUS_DELETING);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
