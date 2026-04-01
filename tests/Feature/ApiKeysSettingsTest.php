<?php

namespace Tests\Feature;

use App\Livewire\Settings\ApiKeys;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiKeysSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_api_keys_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.api-keys'))
            ->assertOk();
    }

    public function test_org_admin_can_create_token_with_granular_permissions(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->assertNotNull($org);

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->set('organization_id', $org->id)
            ->set('token_name', 'CI')
            ->set('selected_abilities', ['servers.read', 'sites.deploy'])
            ->call('createToken')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'CI',
        ]);

        $token = ApiToken::query()->where('name', 'CI')->first();
        $this->assertNotNull($token);
        $this->assertEqualsCanonicalizing(['servers.read', 'sites.deploy'], $token->abilities ?? []);
    }

    public function test_create_requires_at_least_one_permission(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->set('organization_id', $org->id)
            ->set('token_name', 'Empty')
            ->set('selected_abilities', [])
            ->call('createToken')
            ->assertHasErrors(['selected_abilities']);
    }

    public function test_invalid_whitelist_ip_fails_validation(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->set('organization_id', $org->id)
            ->set('token_name', 'Bad IP')
            ->set('token_allowed_ips_text', 'not-an-ip')
            ->set('selected_abilities', ['servers.read'])
            ->call('createToken')
            ->assertHasErrors(['token_allowed_ips_text']);
    }

    public function test_comma_separated_ips_are_accepted(): void
    {
        $this->assertEquals(
            ['203.0.113.1', '203.0.113.2'],
            ApiToken::parseAllowedIpsInput('203.0.113.1, 203.0.113.2', 'ips')
        );
    }

    public function test_non_admin_sees_no_organization_selector_options(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->assertSet('organization_id', null);
    }

    public function test_create_blocked_when_paid_plan_required_and_org_not_on_pro(): void
    {
        config(['dply.api_tokens_require_paid_plan' => true]);

        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->assertNotNull($org);

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->set('organization_id', $org->id)
            ->set('token_name', 'CI')
            ->set('selected_abilities', ['servers.read'])
            ->call('createToken')
            ->assertHasErrors(['token_name']);
    }

    public function test_revoke_token_uses_confirmation_modal_before_deleting(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->assertNotNull($org);

        ['token' => $token] = ApiToken::createToken($user, $org, 'CLI token', null, ['servers.read']);

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->call(
                'openConfirmActionModal',
                'revokeToken',
                [$token->id],
                'Revoke token',
                'Revoke this token? It will stop working immediately.',
                'Revoke',
                true
            )
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'revokeToken')
            ->assertSet('confirmActionModalArguments', [$token->id]);

        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);

        Livewire::actingAs($user)
            ->test(ApiKeys::class)
            ->call(
                'openConfirmActionModal',
                'revokeToken',
                [$token->id],
                'Revoke token',
                'Revoke this token? It will stop working immediately.',
                'Revoke',
                true
            )
            ->call('confirmActionModal');

        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }
}
