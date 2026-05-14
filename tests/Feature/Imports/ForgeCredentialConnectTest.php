<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ForgeCredentialConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_store_forge_validates_required_fields(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('forge_api_token', '')
            ->call('storeForge')
            ->assertHasErrors('forge_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_store_forge_persists_when_token_valid(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
        ]);

        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('forge_name', 'Agency Forge')
            ->set('forge_api_token', 'forge_valid_token')
            ->call('storeForge')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('provider_credentials', [
            'provider' => 'forge',
            'name' => 'Agency Forge',
            'user_id' => $user->id,
        ]);
    }

    public function test_store_forge_rejects_invalid_token_and_does_not_persist(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('forge_api_token', 'forge_bad_token')
            ->call('storeForge')
            ->assertHasErrors('forge_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_verify_credential_rechecks_token(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'forge',
            'credentials' => ['api_token' => 'forge_valid_token'],
        ]);

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->call('verifyCredential', $credential->id)
            ->assertHasNoErrors();
    }

    public function test_credentials_sidebar_includes_forge_tab(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $response = $this->actingAs($user)->get(route('organizations.credentials', ['organization' => $org, 'provider' => 'forge']));

        $response->assertOk()
            ->assertSee('Migrate sites from Laravel Forge to dply')
            ->assertSee('Connect Laravel Forge')
            ->assertSee('Migrate from');
    }
}
