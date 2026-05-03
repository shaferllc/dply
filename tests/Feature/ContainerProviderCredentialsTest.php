<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContainerProviderCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_panels_visible_only_when_provider_is_enabled(): void
    {
        config([
            'server_providers.enabled.digitalocean_app_platform' => false,
            'server_providers.enabled.aws_app_runner' => false,
        ]);
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

        $response->assertOk()
            ->assertDontSee('DigitalOcean App Platform')
            ->assertDontSee('AWS App Runner');
    }

    public function test_do_app_platform_panel_renders_value_prop(): void
    {
        config(['server_providers.enabled.digitalocean_app_platform' => true]);
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', [
            'organization' => $org,
            'provider' => 'digitalocean_app_platform',
        ]));

        $response->assertOk()
            ->assertSee('Container backend')
            ->assertSee('DigitalOcean App Platform');
    }

    public function test_aws_app_runner_panel_renders_value_prop(): void
    {
        config(['server_providers.enabled.aws_app_runner' => true]);
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', [
            'organization' => $org,
            'provider' => 'aws_app_runner',
        ]));

        $response->assertOk()
            ->assertSee('Container backend')
            ->assertSee('App Runner');
    }

    public function test_store_do_app_platform_credential(): void
    {
        config(['server_providers.enabled.digitalocean_app_platform' => true]);
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('active_provider', 'digitalocean_app_platform')
            ->set('do_app_platform_name', 'Production')
            ->set('do_app_platform_api_token', 'dop_v1_abcdef')
            ->call('storeDigitalOceanAppPlatform');

        $this->assertDatabaseHas('provider_credentials', [
            'user_id' => $user->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'Production',
        ]);
    }

    public function test_store_aws_app_runner_credential(): void
    {
        config(['server_providers.enabled.aws_app_runner' => true]);
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('active_provider', 'aws_app_runner')
            ->set('aws_app_runner_name', 'AppRunner US')
            ->set('aws_app_runner_access_key_id', 'AKIA1234567890')
            ->set('aws_app_runner_secret_access_key', 'verysecret')
            ->set('aws_app_runner_region', 'us-west-2')
            ->call('storeAwsAppRunner');

        $cred = ProviderCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', 'aws_app_runner')
            ->first();
        $this->assertNotNull($cred);
        $this->assertSame('AppRunner US', $cred->name);
        $this->assertSame('AKIA1234567890', $cred->credentials['access_key_id']);
        $this->assertSame('us-west-2', $cred->credentials['region']);
    }

    public function test_aws_app_runner_validates_required_fields(): void
    {
        config(['server_providers.enabled.aws_app_runner' => true]);
        $user = $this->ownerWithOrg();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('active_provider', 'aws_app_runner')
            ->call('storeAwsAppRunner')
            ->assertHasErrors(['aws_app_runner_access_key_id', 'aws_app_runner_secret_access_key']);

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
