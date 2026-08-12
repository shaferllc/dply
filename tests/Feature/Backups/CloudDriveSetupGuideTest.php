<?php
use App\Livewire\Backups\Storage as BackupsStorage;
use App\Models\BackupConfiguration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function cloudGuideUser(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}

test('the add-destination modal opens and renders dropbox fields', function () {
    [$user] = cloudGuideUser();

    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->assertSet('showDestinationModal', true)
        ->assertSet('destination_create_mode', 'connect')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->assertSee('Setting up Dropbox', false)
        ->assertSee('Dropbox developers site', false)
        ->assertSee('https://www.dropbox.com/developers', false)
        ->assertSee('App key', false)
        ->assertSee('Refresh token', false)
        ->assertSee('files.content.write', false);
});

test('the authorize url appears once an app key is entered', function () {
    [$user] = cloudGuideUser();

    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->set('destinationForm.dropbox.app_key', 'abc123key')
        ->assertSee('token_access_type=offline', false)
        ->assertSee('abc123key', false);
});

test('the oauth button only appears when the deployment has a dropbox app', function () {
    [$user] = cloudGuideUser();

    // Not configured: the manual guide must still stand on its own, so a
    // self-hoster without a Dropbox app is never blocked.
    config(['services.dropbox.client_id' => null, 'services.dropbox.client_secret' => null]);

    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->assertDontSee('Continue with Dropbox', false)
        ->assertSee('Setting up Dropbox', false);

    config(['services.dropbox.client_id' => 'appkey', 'services.dropbox.client_secret' => 'appsecret']);

    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->assertSee('Continue with Dropbox', false);
});

test('the oauth redirect asks dropbox for offline access', function () {
    [$user, $org] = cloudGuideUser();
    config(['services.dropbox.client_id' => 'appkey', 'services.dropbox.client_secret' => 'appsecret']);
    session(['current_organization_id' => $org->id]);

    $response = $this->actingAs($user)->get(route('credentials.oauth.dropbox.redirect'));

    $target = $response->headers->get('Location');

    // Without offline access Dropbox returns no refresh token and every
    // scheduled dump breaks a few hours later.
    expect($target)->toContain('token_access_type=offline')
        ->toContain('files.content.write')
        ->toContain('files.content.read')
        ->toContain('client_id=appkey');
});

test('the oauth redirect is refused when no dropbox app is configured', function () {
    [$user, $org] = cloudGuideUser();
    config(['services.dropbox.client_id' => null, 'services.dropbox.client_secret' => null]);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)
        ->get(route('credentials.oauth.dropbox.redirect'))
        ->assertRedirect();

    expect(session('error'))->toContain('not configured');
});

test('the oauth panel warns about the permissions tab before you click', function () {
    [$user] = cloudGuideUser();
    config(['services.dropbox.client_id' => 'appkey', 'services.dropbox.client_secret' => 'appsecret']);

    // Dropbox rejects scope_not_granted on its OWN error page, so the callback
    // never fires and there is no server-side moment left to explain it.
    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_DROPBOX)
        ->assertSee('files.content.write', false)
        ->assertSee('Permissions tab', false);
});

test('google drive offers one-click connect when configured', function () {
    [$user] = cloudGuideUser();

    config(['services.google_drive.client_id' => null, 'services.google_drive.client_secret' => null]);
    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_GOOGLE_DRIVE)
        ->assertDontSee('Continue with Google', false)
        ->assertSee('Setting up Google Drive', false);

    config(['services.google_drive.client_id' => 'gid', 'services.google_drive.client_secret' => 'gsecret']);
    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_GOOGLE_DRIVE)
        ->assertSee('Continue with Google', false)
        ->assertSee('drive.file', false);
});

test('the google redirect forces consent so a refresh token always comes back', function () {
    [$user, $org] = cloudGuideUser();
    config(['services.google_drive.client_id' => 'gid', 'services.google_drive.client_secret' => 'gsecret']);
    session(['current_organization_id' => $org->id]);

    $target = $this->actingAs($user)
        ->get(route('credentials.oauth.google-drive.redirect'))
        ->headers->get('Location');

    // access_type=offline alone only returns a refresh token on the FIRST ever
    // consent — a reconnect would silently get none and expire within the hour.
    expect($target)->toContain('access_type=offline')
        ->toContain('prompt=consent')
        ->toContain('drive.file')
        ->toContain('client_id=gid');
});

test('the google panel warns that testing mode expires refresh tokens', function () {
    [$user] = cloudGuideUser();
    config(['services.google_drive.client_id' => 'gid', 'services.google_drive.client_secret' => 'gsecret']);

    // A destination connected from a Testing-mode app works, then dies 7 days
    // later with nothing in dply's own config having changed.
    Livewire::actingAs($user)
        ->test(BackupsStorage::class)
        ->call('openDestinationModal')
        ->set('destinationForm.provider', BackupConfiguration::PROVIDER_GOOGLE_DRIVE)
        ->assertSee('7 days', false)
        ->assertSee('Publish your Google Cloud consent screen', false);
});
