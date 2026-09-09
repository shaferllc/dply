<?php

namespace Tests\Feature\CredentialTest;

use App\Livewire\Credentials\AddProviderCredentialModal;
use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Support\ServerProviderGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

// DNS/CDN providers default off (config server_providers.enabled.*); enable the
// ones these tests connect so ServerProviderGate::enabled() doesn't refuse them.
beforeEach(function (): void {
    config([
        'server_providers.enabled.gandi' => true,
        'server_providers.enabled.namecheap' => true,
        'server_providers.enabled.vercel_dns' => true,
        'server_providers.enabled.cloudflare' => true,
    ]);
});

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('credentials index redirects guest', function () {
    $response = $this->get(route('credentials.index'));

    $response->assertRedirect(route('login', absolute: false));
});

test('credentials index is displayed', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('credentials.index'));

    $response->assertRedirect(route('organizations.credentials', $org, false));
});

test('organization credentials page is displayed', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertOk();
    $response->assertSee('Credentials');
    $response->assertSee('Connect a provider');
    // The page lists what you have; the provider catalog lives in the modal's
    // picker rather than as 26 cards on the page (redesigned 2026-08-22).
    $response->assertDontSee('Not connected');
});

test('credentials index forbidden for deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    $response = $this->actingAs($user)->get(route('credentials.index'));

    $response->assertForbidden();
});

test('the credential table lists a saved token by name', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->assertSee('No credentials yet.');

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Production DO',
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->dispatch('provider-credential-created', provider: 'digitalocean', credentialId: $credential->id)
        ->assertSee('Production DO')
        ->assertSee('DigitalOcean')
        ->assertDontSee('No credentials yet.');
});

/*
 * The row filters narrow YOUR credentials. The capability tabs they replaced
 * filtered the catalog, so "DNS" hid providers you had not connected either way.
 */
test('the filters narrow the table to your own rows', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    ProviderCredential::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'provider' => 'digitalocean', 'name' => 'Compute token',
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'provider' => 'namecheap', 'name' => 'Registrar token',
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->assertSee('Compute token')
        ->assertSee('Registrar token')
        // Namecheap is DNS-only; DigitalOcean does compute AND dns, so the
        // compute filter is the one that separates them.
        ->call('setFilter', 'compute')
        ->assertSet('filter', 'compute')
        ->assertSee('Compute token')
        ->assertDontSee('Registrar token')
        ->call('setFilter', 'dns')
        ->assertSee('Registrar token')
        ->assertSee('Compute token');
});

test('an unknown filter falls back to showing everything', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->call('setFilter', 'not-a-filter')
        ->assertSet('filter', 'all');
});

test('a rejected token surfaces the provider error and sorts to the top', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    ProviderCredential::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'provider' => 'digitalocean', 'name' => 'Healthy token',
        'last_validated_at' => now(),
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'provider' => 'cloudflare', 'name' => 'Broken token',
        'validation_error' => 'Invalid API token (10000)',
    ]);

    $component = Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        // validation_error was stored and counted but never displayed.
        ->assertSee('Invalid API token (10000)')
        ->assertSee("Can't connect");

    $rows = $component->instance()->rows();
    expect($rows[0]['name'])->toBe('Broken token');

    $component->call('setFilter', 'attention')
        ->assertSee('Broken token')
        ->assertDontSee('Healthy token');
});

test('credentials store validates required fields', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('do_api_token', '')
        ->call('storeDigitalOcean')
        ->assertHasErrors('do_api_token');
});

test('credentials store redirects back when token invalid', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('do_api_token', 'dop_v1_invalid')
        ->call('storeDigitalOcean')
        ->assertHasErrors('do_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('credentials can be destroyed by owner', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $cred = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->call('destroy', $cred->id);

    $this->assertModelMissing($cred);
});

test('credentials destroy returns 403 for ordinary org member', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);
    $cred = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    session(['current_organization_id' => $org->id]);

    try {
        Livewire::actingAs($member)
            ->test(CredentialsIndex::class, ['organization' => $org])
            ->call('destroy', $cred->id);
    } catch (AuthorizationException) {
        // Livewire may surface the policy deny as an exception.
    }

    $this->assertDatabaseHas('provider_credentials', ['id' => $cred->id]);
});

test('credentials destroy returns 403 for non member', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($otherUser->id, ['role' => 'owner']);
    $cred = ProviderCredential::factory()->create([
        'user_id' => $otherUser->id,
        'organization_id' => $org->id,
    ]);

    try {
        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->call('destroy', $cred->id);
    } catch (AuthorizationException $e) {
        $this->addToAssertionCount(1);

        return;
    }

    $this->assertDatabaseHas('provider_credentials', ['id' => $cred->id]);
});

test('gandi credential can be connected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('gandi_api_token', 'pat-gandi-secret')
        ->call('storeGandi')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $org->id,
        'provider' => 'gandi',
        'name' => 'Gandi',
    ]);
});

test('gandi credential requires a token', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('gandi_api_token', '')
        ->call('storeGandi')
        ->assertHasErrors('gandi_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('namecheap credential can be connected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('namecheap_name', 'Agency DNS')
        ->set('namecheap_api_user', 'acme')
        ->set('namecheap_api_key', 'nc-secret-key')
        ->call('storeNamecheap')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $org->id,
        'provider' => 'namecheap',
        'name' => 'Agency DNS',
    ]);
});

test('vercel dns credential stores optional team id', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('vercel_dns_api_token', 'vc-secret')
        ->set('vercel_dns_team_id', 'team_abc123')
        ->call('storeVercelDns')
        ->assertHasNoErrors();

    $credential = ProviderCredential::query()
        ->where('organization_id', $org->id)
        ->where('provider', 'vercel_dns')
        ->firstOrFail();

    expect($credential->credentials['team_id'])->toBe('team_abc123');
});

test('cdn tab lists only cdn capable providers', function () {
    $ids = CredentialsIndex::credentialProviderIds('cdn');

    expect($ids)->toContain('cloudflare');
    expect($ids)->toContain('vercel_dns');
    expect($ids)->not->toContain('namecheap');
    expect($ids)->not->toContain('digitalocean');
});

test('compute vm providers are grouped under vps and cloud not infrastructure hub label', function () {
    config([
        'server_providers.enabled.upcloud' => true,
        'server_providers.enabled.linode' => true,
    ]);
    Feature::define('provider.upcloud', fn (): bool => true);
    Feature::define('provider.linode', fn (): bool => true);
    Feature::flushCache();

    $nav = CredentialsIndex::credentialProviderNav();
    $groupById = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $groupById[$item['id']] = $group['label'];
        }
    }

    $vpsGroup = __('VPS & cloud');

    expect($groupById['upcloud'] ?? null)->toBe($vpsGroup);
    expect($groupById['linode'] ?? null)->toBe($vpsGroup);
    expect(array_column($nav, 'label'))->not->toContain(__('Infrastructure'));
});

/*
 * Backup destinations had no delete anywhere in the app until 2026-08-22 — you
 * could add a bucket and never remove it.
 */
test('a backup destination can be removed, and its schedules fall back to the server', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $destination = BackupConfiguration::factory()->forOrganization($org)->create(['name' => 'acme-backups']);
    // No factory for BackupSchedule; these four columns are the table's only
    // non-nullable ones without a default.
    $schedule = BackupSchedule::create([
        'target_type' => 'database',
        'target_id' => (string) Str::ulid(),
        'cron_expression' => '0 3 * * *',
        'backup_configuration_id' => $destination->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->assertSee('acme-backups')
        ->call('promptDeleteDestination', (string) $destination->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deleteDestination');

    // The confirm has to name what breaks, not just ask "are you sure?". The
    // dialog renders from a layout slot, so read the message off the component.
    expect($component->instance()->confirmActionModalMessage)
        ->toContain('keep its dumps on the server');

    $component->call('deleteDestination', (string) $destination->id)
        ->assertDontSee('acme-backups');

    expect(BackupConfiguration::find($destination->id))->toBeNull()
        // No FK backs this column, so an orphaned pointer would fail mid-ship.
        ->and($schedule->fresh()->backup_configuration_id)->toBeNull();
});

test('a destination from another organization cannot be removed', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $foreign = BackupConfiguration::factory()->forOrganization(Organization::factory()->create())->create();

    expect(fn () => Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->call('deleteDestination', (string) $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect(BackupConfiguration::find($foreign->id))->not->toBeNull();
});

test('parked coming-soon providers are gone from the picker', function () {
    // COMING_SOON is commented out in ServerProviderGate; visible() is then
    // enabled() alone, so only shipping providers reach the nav.
    $labels = collect(CredentialsIndex::credentialProviderNav())
        ->flatMap(fn (array $g) => $g['items'])
        ->pluck('label');

    // Which providers are enabled varies by env config, so the invariant is the
    // assertion: nothing in the picker is a placeholder any more.
    expect($labels)->not->toBeEmpty()
        ->and($labels)->toContain('DigitalOcean');

    expect(collect(CredentialsIndex::credentialProviderNav())
        ->flatMap(fn (array $g) => $g['items'])
        ->filter(fn (array $i) => ! empty($i['comingSoon'])))
        ->toBeEmpty();

    expect(ServerProviderGate::comingSoon('namecheap'))->toBeFalse()
        ->and(ServerProviderGate::comingSoon('aws'))->toBeFalse();
});

/*
 * The panel inside the add-credential modal belongs to AddProviderCredentialModal,
 * not to Index — so its Remove button set confirm state on a component whose view
 * rendered no dialog, and the click did nothing (fixed 2026-08-22).
 */
test('the modal panel confirms a credential removal against its own component', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Production DO',
    ]);

    $modal = Livewire::actingAs($user)
        ->test(AddProviderCredentialModal::class)
        ->call('promptDestroyCredential', (string) $credential->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'destroy');

    // The message names the credential rather than asking "Remove this credential?".
    expect($modal->instance()->confirmActionModalMessage)->toContain('Production DO');

    $modal->call('destroy', (string) $credential->id);

    expect(ProviderCredential::find($credential->id))->toBeNull();
});

test('a member cannot prompt a credential removal', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($member)
        ->test(AddProviderCredentialModal::class)
        ->call('promptDestroyCredential', (string) $credential->id)
        ->assertForbidden();

    expect(ProviderCredential::find($credential->id))->not->toBeNull();
});
