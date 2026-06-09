<?php

namespace Tests\Feature\BackupConfigurationTest;

use App\Livewire\Settings\BackupConfigurations;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Create a user with a single organization membership. Single org means
 * User::currentOrganization() returns it without needing a session set.
 */
function userInNewOrg(?Organization $org = null): array
{
    $user = User::factory()->create();
    $org ??= Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}

test('guest cannot view backup configurations', function () {
    $this->get(route('profile.backup-configurations'))
        ->assertRedirect();
});

test('authenticated user can view backup destinations page', function () {
    [$user] = userInNewOrg();

    $this->actingAs($user)
        ->get(route('profile.backup-configurations'))
        ->assertOk()
        ->assertSee('Organization backup destinations', false);
});

test('user can create custom s3 backup destination under their org', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class)
        ->set('createForm.name', 'Staging bucket')
        ->set('createForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
        ->set('createForm.s3.access_key', 'AKIAEXAMPLE')
        ->set('createForm.s3.secret', 'secret-value')
        ->set('createForm.s3.bucket', 'my-bucket')
        ->set('createForm.s3.region', 'nl-ams1')
        ->set('createForm.s3.endpoint', 'https://s3.example.com')
        ->set('createForm.s3.use_path_style', true)
        ->call('createConfiguration')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('backup_configurations', [
        'organization_id' => $org->id,
        'created_by_user_id' => $user->id,
        'name' => 'Staging bucket',
        'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
    ]);

    $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
    expect($row)->not->toBeNull();
    expect($row->config['access_key'])->toBe('AKIAEXAMPLE');
    expect($row->config['secret'])->toBe('secret-value');
    expect($row->config['use_path_style'])->toBeTrue();
});

test('local provider is no longer accepted by the form', function () {
    [$user] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class)
        ->set('createForm.name', 'Should be rejected')
        ->set('createForm.provider', 'local')
        ->call('createConfiguration')
        ->assertHasErrors(['createForm.provider']);

    $this->assertDatabaseCount('backup_configurations', 0);
});

test('search filters destinations by name', function () {
    [$user, $org] = userInNewOrg();
    BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Alpha backups']);
    BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Beta archive']);

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class)
        ->set('search', 'Beta')
        ->assertSee('Beta archive', false)
        ->assertDontSee('Alpha backups', false);
});

test('teammates can view and edit each others destinations', function () {
    $org = Organization::factory()->create();
    [$alice] = userInNewOrg($org);
    [$bob] = userInNewOrg($org);

    $config = BackupConfiguration::factory()
        ->forOrganization($org)
        ->createdBy($alice)
        ->create(['name' => 'Shared bucket']);

    // Bob sees what Alice created.
    Livewire::actingAs($bob)
        ->test(BackupConfigurations::class)
        ->assertSee('Shared bucket', false);

    // Bob can rename it — destinations are org-shared, not creator-owned.
    Livewire::actingAs($bob)
        ->test(BackupConfigurations::class)
        ->call('startEdit', $config->id)
        ->set('editForm.name', 'Renamed by Bob')
        ->call('updateConfiguration')
        ->assertHasNoErrors();

    expect($config->fresh()->name)->toBe('Renamed by Bob');
});

test('user in different org cannot delete destination', function () {
    [, $ownerOrg] = userInNewOrg();
    $config = BackupConfiguration::factory()->forOrganization($ownerOrg)->create();

    [$outsider] = userInNewOrg();

    // membership in a brand-new org
    Livewire::actingAs($outsider)
        ->test(BackupConfigurations::class)
        ->call('deleteConfiguration', $config->id)
        ->assertForbidden();

    $this->assertDatabaseHas('backup_configurations', ['id' => $config->id]);
});
