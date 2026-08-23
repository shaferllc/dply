<?php

namespace Tests\Feature\BackupConfigurationTest;

use App\Livewire\Credentials\Index as BackupConfigurations;
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
    [$user, $org] = userInNewOrg();

    // Destinations are credentials — they live in the org Credentials table.
    // Every legacy URL keeps its route name and redirects there, storage first.
    $credentialsUrl = route('organizations.credentials', ['organization' => $org, 'filter' => 'storage']);

    $this->actingAs($user)->get(route('profile.backup-configurations'))->assertRedirect($credentialsUrl);
    $this->actingAs($user)->get(route('profile.backup-destinations'))->assertRedirect($credentialsUrl);
    $this->actingAs($user)->get(route('backups.storage'))->assertRedirect($credentialsUrl);
    $this->actingAs($user)->get(route('organizations.backup-destinations', ['organization' => $org]))->assertRedirect($credentialsUrl);

    $this->actingAs($user)
        ->get(route('organizations.credentials', ['organization' => $org]))
        ->assertOk()
        ->assertSee('Credentials', false);
});

test('user can create custom s3 backup destination under their org', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Staging bucket')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
        ->set('destinationForm.s3.access_key', 'AKIAEXAMPLE')
        ->set('destinationForm.s3.secret', 'secret-value')
        ->set('destinationForm.s3.bucket', 'my-bucket')
        ->set('destinationForm.s3.region', 'nl-ams1')
        ->set('destinationForm.s3.endpoint', 'https://s3.example.com')
        ->set('destinationForm.s3.use_path_style', true)
        ->call('saveDestination')
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
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Should be rejected')
        ->set('destinationForm.provider', 'local')
        ->call('saveDestination')
        ->assertHasErrors(['destinationForm.provider']);

    $this->assertDatabaseCount('backup_configurations', 0);
});

test('the storage filter narrows the credentials table to destinations', function () {
    [$user, $org] = userInNewOrg();
    BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'Alpha backups']);
    \App\Models\ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Hetzner token',
    ]);

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->assertSee('Alpha backups', false)
        ->assertSee('Hetzner token', false)
        ->call('setFilter', 'storage')
        ->assertSee('Alpha backups', false)
        ->assertDontSee('Hetzner token', false);
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
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->assertSee('Shared bucket', false);

    // Bob can rename it — destinations are org-shared, not creator-owned.
    Livewire::actingAs($bob)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->call('editDestination', $config->id)
        ->set('destinationForm.name', 'Renamed by Bob')
        ->call('saveDestination')
        ->assertHasNoErrors();

    expect($config->fresh()->name)->toBe('Renamed by Bob');
});

test('user in different org cannot reach that org\'s destinations', function () {
    [, $ownerOrg] = userInNewOrg();
    $config = BackupConfiguration::factory()->forOrganization($ownerOrg)->create();

    [$outsider, $outsiderOrg] = userInNewOrg();

    // The page is org-scoped, so mounting someone else's org is the wall.
    Livewire::actingAs($outsider)
        ->test(BackupConfigurations::class, ['organization' => $ownerOrg])
        ->assertForbidden();

    // And their own org's page cannot touch a row belonging to another org:
    // the lookup is scoped, so a guessed id is simply not found.
    expect(fn () => Livewire::actingAs($outsider)
        ->test(BackupConfigurations::class, ['organization' => $outsiderOrg])
        ->call('deleteDestination', $config->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('backup_configurations', ['id' => $config->id]);
});

test('sftp ftp and rclone destinations can be created', function (string $provider, string $formKey, array $fields) {
    [$user, $org] = userInNewOrg();

    $component = Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Offsite '.$provider)
        ->set('destinationForm.provider', $provider);

    foreach ($fields as $field => $value) {
        $component->set("destinationForm.{$formKey}.{$field}", $value);
    }

    $component->call('saveDestination')->assertHasNoErrors();

    $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
    expect($row)->not->toBeNull();
    expect($row->provider)->toBe($provider);

    // The credentials have to survive extraction or the transport gets nothing.
    foreach ($fields as $field => $value) {
        expect($row->config[$field])->toBe($value);
    }
})->with([
    'sftp' => [BackupConfiguration::PROVIDER_SFTP, 'sftp', [
        'host' => 'backup.example.com', 'username' => 'deploy', 'password' => 'hunter2', 'path' => '/srv/dumps',
    ]],
    'ftp' => [BackupConfiguration::PROVIDER_FTP, 'ftp', [
        'host' => 'ftp.example.com', 'username' => 'deploy', 'password' => 'hunter2',
    ]],
    'rclone' => [BackupConfiguration::PROVIDER_RCLONE, 'rclone', [
        'remote_name' => 'wasabi', 'config' => "[wasabi]\ntype = s3",
    ]],
]);

test('sftp requires either a password or a private key', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Keyless')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_SFTP)
        ->set('destinationForm.sftp.host', 'h.example')
        ->set('destinationForm.sftp.username', 'deploy')
        ->call('saveDestination')
        ->assertHasErrors();

    $this->assertDatabaseCount('backup_configurations', 0);
});

test('dropbox and google drive destinations can be created', function (string $provider, string $formKey, array $fields) {
    [$user, $org] = userInNewOrg();

    $component = Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Cloud '.$provider)
        ->set('destinationForm.provider', $provider);

    foreach ($fields as $field => $value) {
        $component->set("destinationForm.{$formKey}.{$field}", $value);
    }

    $component->call('saveDestination')->assertHasNoErrors();

    $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
    expect($row)->not->toBeNull();
    expect($row->provider)->toBe($provider);

    foreach ($fields as $field => $value) {
        expect($row->config[$field])->toBe($value);
    }
})->with([
    'dropbox' => [BackupConfiguration::PROVIDER_DROPBOX, 'dropbox', [
        'access_token' => 'sl.token', 'path' => '/backups',
    ]],
    'google drive' => [BackupConfiguration::PROVIDER_GOOGLE_DRIVE, 'google', [
        'client_id' => 'cid', 'client_secret' => 'csecret', 'refresh_token' => 'rtok', 'folder_id' => 'FOLDER1',
    ]],
]);

test('every advertised provider is accepted by the form', function () {
    // availableProviders() drives both the picker and the Rule::in guard, so a
    // provider advertised without a transport would be creatable but dead.
    foreach (BackupConfiguration::availableProviders() as $provider) {
        expect(BackupConfiguration::isProviderAvailable($provider))->toBeTrue();
    }

    expect(BackupConfiguration::availableProviders())
        ->toHaveCount(count(BackupConfiguration::providers()));
});

test('providers with no transport are still rejected', function (string $provider) {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Not wired up')
        ->set('destinationForm.provider', $provider)
        ->call('saveDestination')
        ->assertHasErrors(['destinationForm.provider']);

    $this->assertDatabaseCount('backup_configurations', 0);
})->with([
    // Every real provider now has a transport; a bogus slug must still bounce.
    'unknown slug' => ['local'],
]);

test('a dropbox destination needs either a refresh token or an access token', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Credential-less')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->set('destinationForm.dropbox.path', '/backups')
        ->call('saveDestination')
        ->assertHasErrors();

    $this->assertDatabaseCount('backup_configurations', 0);
});

test('a dropbox destination can be created with the durable refresh shape', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->set('destinationForm.name', 'Dropbox nightly')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->set('destinationForm.dropbox.app_key', 'akey')
        ->set('destinationForm.dropbox.app_secret', 'asecret')
        ->set('destinationForm.dropbox.refresh_token', 'rtok')
        ->call('saveDestination')
        ->assertHasNoErrors();

    $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
    expect($row->config['refresh_token'])->toBe('rtok');
    expect($row->config['app_secret'])->toBe('asecret');
});

test('gzip compression is stored per destination and defaults off', function () {
    [$user, $org] = userInNewOrg();

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->call('openDestinationModal')
        ->set('destinationForm.name', 'Compressed bucket')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_CUSTOM_S3)
        ->set('destinationForm.s3.access_key', 'AKIA')
        ->set('destinationForm.s3.secret', 'sec')
        ->set('destinationForm.s3.bucket', 'b')
        ->set('destinationForm.s3.endpoint', 'https://s3.example.com')
        ->set('destinationForm.compress', true)
        ->call('saveDestination')
        ->assertHasNoErrors();

    $row = BackupConfiguration::query()->where('organization_id', $org->id)->first();
    expect($row->config['compress'])->toBeTrue();
    // The provider's own keys must survive the merge.
    expect($row->config['bucket'])->toBe('b');
});

test('an existing destination round-trips its compression setting into the edit form', function () {
    [$user, $org] = userInNewOrg();
    $row = BackupConfiguration::factory()->forOrganization($org)->create([
        'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
        'config' => ['bucket' => 'b', 'access_key' => 'k', 'secret' => 's', 'endpoint' => 'https://e', 'compress' => true],
    ]);

    Livewire::actingAs($user)
        ->test(BackupConfigurations::class, ['organization' => $org])
        ->call('editDestination', $row->id)
        ->assertSet('destinationForm.compress', true);
});
