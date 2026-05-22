<?php


namespace Tests\Feature\ProfileSecurityTest;
use App\Livewire\Settings\Security;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('cannot unlink only oauth without password or passkey', function () {
    $user = User::factory()->create(['password' => null]);
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-test-1',
        'nickname' => 'tester',
        'access_token' => 'token',
    ]);

    Livewire::actingAs($user)
        ->test(Security::class)
        ->call('unlinkOAuthAccount', $account->id)
        ->assertHasErrors(['unlink']);

    expect(SocialAccount::query()->whereKey($account->id)->exists())->toBeTrue();
});

test('can unlink oauth when password is set', function () {
    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-test-2',
        'nickname' => 'tester',
        'access_token' => 'token',
    ]);

    Livewire::actingAs($user)
        ->test(Security::class)
        ->call('unlinkOAuthAccount', $account->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
});

test('can unlink oauth when a passkey is registered', function () {
    $user = User::factory()->create(['password' => null]);
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-test-3',
        'nickname' => 'tester',
        'access_token' => 'token',
    ]);

    seedPasskey($user, name: 'Office MacBook');

    Livewire::actingAs($user)
        ->test(Security::class)
        ->call('unlinkOAuthAccount', $account->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
});

test('can rename a passkey from security settings', function () {
    $user = User::factory()->create();
    $passkey = seedPasskey($user, name: 'Old name');

    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('passkeyAliases.'.$passkey->getKey(), 'Work laptop')
        ->call('savePasskeyAlias', $passkey->getKey())
        ->assertHasNoErrors();

    expect($passkey->fresh()->name)->toBe('Work laptop');
});

test('clearing a passkey name falls back to default label', function () {
    $user = User::factory()->create();
    $passkey = seedPasskey($user, name: 'My MacBook');

    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('passkeyAliases.'.$passkey->getKey(), '')
        ->call('savePasskeyAlias', $passkey->getKey())
        ->assertHasNoErrors();

    // The package's `passkeys.name` column is NOT NULL, so the component
    // substitutes a generic label instead of dropping the row to null.
    expect($passkey->fresh()->name)->toBe(__('Passkey'));
});

test('remove passkey blocks when it is the only sign in method', function () {
    $user = User::factory()->create(['password' => null]);
    $passkey = seedPasskey($user, name: 'Only key');

    Livewire::actingAs($user)
        ->test(Security::class)
        ->call('removePasskey', $passkey->getKey())
        ->assertHasErrors(['passkey']);

    $this->assertDatabaseHas('passkeys', ['id' => $passkey->getKey()]);
});

test('remove passkey succeeds when a password is set', function () {
    $user = User::factory()->create();
    $passkey = seedPasskey($user, name: 'Throwaway');

    Livewire::actingAs($user)
        ->test(Security::class)
        ->call('removePasskey', $passkey->getKey())
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->getKey()]);
});

/**
 * Insert a Passkey row directly with synthetic credential material — bypasses the
 * package's registration ceremony, which needs a real authenticator to test
 * end-to-end. The dply-side flows we care about (name editing, removal lockout,
 * OAuth unlink) only read `name` / count rows, so a stub credential is sufficient.
 */
function seedPasskey(User $user, string $name): Passkey
{
    return Passkey::query()->forceCreate([
        'user_id' => $user->getKey(),
        'name' => $name,
        'credential_id' => 'stub-credential-'.uniqid(),
        'credential' => ['aaguid' => 'stub-aaguid', 'public_key' => 'stub-public-key'],
    ]);
}