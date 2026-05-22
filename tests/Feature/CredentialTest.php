<?php

namespace Tests\Feature;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['provider.fly_io', 'provider.linode', 'provider.vultr', 'provider.upcloud', 'provider.scaleway', 'provider.aws', 'provider.equinix_metal'];

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_credentials_index_redirects_guest(): void
    {
        $response = $this->get(route('credentials.index'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_credentials_index_is_displayed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('credentials.index'));

        $response->assertRedirect(route('organizations.credentials', $org, false));
    }

    public function test_organization_credentials_page_is_displayed(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

        $response->assertOk();
        $response->assertSee('Provider credentials');
        $response->assertSee('Server providers');
    }

    public function test_organization_credentials_fly_io_panel_shows_value_prop(): void
    {
        config(['server_providers.enabled.fly_io' => true]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', ['organization' => $org, 'provider' => 'fly_io']));

        $response->assertOk()
            ->assertSee('What Fly.io adds to Dply')
            ->assertSee('Node and static sites')
            ->assertSee('Connect Fly.io');
    }

    public function test_credentials_index_forbidden_for_deployer(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        $response = $this->actingAs($user)->get(route('credentials.index'));

        $response->assertForbidden();
    }

    public function test_credentials_store_validates_required_fields(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('do_api_token', '')
            ->call('storeDigitalOcean')
            ->assertHasErrors('do_api_token');
    }

    public function test_credentials_store_redirects_back_when_token_invalid(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('do_api_token', 'dop_v1_invalid')
            ->call('storeDigitalOcean')
            ->assertHasErrors('do_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_credentials_can_be_destroyed_by_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $cred = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->call('destroy', $cred->id);

        $this->assertModelMissing($cred);
    }

    public function test_credentials_destroy_returns_403_for_non_member(): void
    {
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
    }

    public function test_gandi_credential_can_be_connected(): void
    {
        $user = $this->userWithOrganization();
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
    }

    public function test_gandi_credential_requires_a_token(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('gandi_api_token', '')
            ->call('storeGandi')
            ->assertHasErrors('gandi_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_namecheap_credential_can_be_connected(): void
    {
        $user = $this->userWithOrganization();
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
    }

    public function test_vercel_dns_credential_stores_optional_team_id(): void
    {
        $user = $this->userWithOrganization();
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

        $this->assertSame('team_abc123', $credential->credentials['team_id']);
    }

    public function test_cdn_tab_lists_only_cdn_capable_providers(): void
    {
        $ids = CredentialsIndex::credentialProviderIds('cdn');

        $this->assertContains('cloudflare', $ids);
        $this->assertContains('vercel_dns', $ids);
        $this->assertNotContains('namecheap', $ids);
        $this->assertNotContains('digitalocean', $ids);
    }
}
