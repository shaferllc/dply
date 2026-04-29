<?php

namespace Tests\Feature;

use App\Livewire\Settings\Security;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_request_webauthn_register_options(): void
    {
        $this->post(route('webauthn.register.options'))
            ->assertRedirect();
    }

    public function test_authenticated_user_can_request_webauthn_register_options(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('webauthn.register.options'))
            ->assertSuccessful();
    }

    public function test_webauthn_register_options_falls_back_to_config_when_body_omitted(): void
    {
        Config::set('webauthn.registration.authenticator_attachment', 'platform');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('webauthn.register.options'))
            ->assertSuccessful()
            ->assertJsonPath('authenticatorSelection.authenticatorAttachment', 'platform');
    }

    public function test_webauthn_register_options_respects_platform_choice_in_request_body(): void
    {
        Config::set('webauthn.registration.authenticator_attachment', null);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('webauthn.register.options'), ['authenticator_attachment' => 'platform'])
            ->assertSuccessful()
            ->assertJsonPath('authenticatorSelection.authenticatorAttachment', 'platform');
    }

    public function test_webauthn_register_options_respects_cross_platform_choice_in_request_body(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('webauthn.register.options'), ['authenticator_attachment' => 'cross-platform'])
            ->assertSuccessful()
            ->assertJsonPath('authenticatorSelection.authenticatorAttachment', 'cross-platform');
    }

    public function test_webauthn_register_options_empty_attachment_skips_config_hint(): void
    {
        Config::set('webauthn.registration.authenticator_attachment', 'platform');

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('webauthn.register.options'), ['authenticator_attachment' => ''])
            ->assertSuccessful();

        $authenticatorSelection = $response->json('authenticatorSelection');
        $this->assertIsArray($authenticatorSelection);
        $this->assertArrayNotHasKey('authenticatorAttachment', $authenticatorSelection);
    }

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

    public function test_can_update_passkey_alias_from_security_settings(): void
    {
        $user = User::factory()->create();

        $credential = WebAuthnCredential::forceCreate([
            'id' => '01HZTESTCREDENTIAL0123456789AB',
            'authenticatable_type' => User::class,
            'authenticatable_id' => $user->getKey(),
            'user_id' => (string) Str::uuid(),
            'rp_id' => 'localhost',
            'origin' => 'https://localhost',
            'public_key' => 'dummy-public-key-material-for-tests',
            'alias' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Security::class)
            ->set('passkeyAliases.'.$credential->getKey(), 'Work laptop')
            ->call('savePasskeyAlias', $credential->getKey())
            ->assertHasNoErrors();

        $this->assertSame('Work laptop', $credential->fresh()->alias);
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
}
