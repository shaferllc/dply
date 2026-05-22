<?php

namespace Tests\Feature;

use App\Livewire\Settings\Security;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the security settings surface for the laravel/passkeys integration. The
 * package owns its own registration / verification endpoint tests inside its own
 * suite, so this file focuses on the dply-specific glue: passkey name editing,
 * passkey deletion lockout guard, and the OAuth-unlink interaction with the
 * presence of a registered passkey.
 */
class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_unlink_only_oauth_without_password_or_passkey(): void
    {
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

        $this->assertTrue(SocialAccount::query()->whereKey($account->id)->exists());
    }

    public function test_can_unlink_oauth_when_password_is_set(): void
    {
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
    }

    public function test_can_unlink_oauth_when_a_passkey_is_registered(): void
    {
        $user = User::factory()->create(['password' => null]);
        $account = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => 'gh-test-3',
            'nickname' => 'tester',
            'access_token' => 'token',
        ]);

        $this->seedPasskey($user, name: 'Office MacBook');

        Livewire::actingAs($user)
            ->test(Security::class)
            ->call('unlinkOAuthAccount', $account->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    public function test_can_rename_a_passkey_from_security_settings(): void
    {
        $user = User::factory()->create();
        $passkey = $this->seedPasskey($user, name: 'Old name');

        Livewire::actingAs($user)
            ->test(Security::class)
            ->set('passkeyAliases.'.$passkey->getKey(), 'Work laptop')
            ->call('savePasskeyAlias', $passkey->getKey())
            ->assertHasNoErrors();

        $this->assertSame('Work laptop', $passkey->fresh()->name);
    }

    public function test_clearing_a_passkey_name_falls_back_to_default_label(): void
    {
        $user = User::factory()->create();
        $passkey = $this->seedPasskey($user, name: 'My MacBook');

        Livewire::actingAs($user)
            ->test(Security::class)
            ->set('passkeyAliases.'.$passkey->getKey(), '')
            ->call('savePasskeyAlias', $passkey->getKey())
            ->assertHasNoErrors();

        // The package's `passkeys.name` column is NOT NULL, so the component
        // substitutes a generic label instead of dropping the row to null.
        $this->assertSame(__('Passkey'), $passkey->fresh()->name);
    }

    public function test_remove_passkey_blocks_when_it_is_the_only_sign_in_method(): void
    {
        $user = User::factory()->create(['password' => null]);
        $passkey = $this->seedPasskey($user, name: 'Only key');

        Livewire::actingAs($user)
            ->test(Security::class)
            ->call('removePasskey', $passkey->getKey())
            ->assertHasErrors(['passkey']);

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->getKey()]);
    }

    public function test_remove_passkey_succeeds_when_a_password_is_set(): void
    {
        $user = User::factory()->create();
        $passkey = $this->seedPasskey($user, name: 'Throwaway');

        Livewire::actingAs($user)
            ->test(Security::class)
            ->call('removePasskey', $passkey->getKey())
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->getKey()]);
    }

    /**
     * Insert a Passkey row directly with synthetic credential material — bypasses the
     * package's registration ceremony, which needs a real authenticator to test
     * end-to-end. The dply-side flows we care about (name editing, removal lockout,
     * OAuth unlink) only read `name` / count rows, so a stub credential is sufficient.
     */
    private function seedPasskey(User $user, string $name): Passkey
    {
        return Passkey::query()->forceCreate([
            'user_id' => $user->getKey(),
            'name' => $name,
            'credential_id' => 'stub-credential-'.uniqid(),
            'credential' => ['aaguid' => 'stub-aaguid', 'public_key' => 'stub-public-key'],
        ]);
    }
}
