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

class PloiCredentialConnectTest extends TestCase
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

    public function test_store_ploi_validates_required_fields(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('ploi_api_token', '')
            ->call('storePloi')
            ->assertHasErrors('ploi_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_store_ploi_persists_when_token_valid(): void
    {
        Http::fake([
            'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1, 'email' => 'x@y.z']], 200),
        ]);

        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('ploi_name', 'My Ploi')
            ->set('ploi_api_token', 'ploi_valid_token')
            ->call('storePloi')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('provider_credentials', [
            'provider' => 'ploi',
            'name' => 'My Ploi',
            'user_id' => $user->id,
        ]);
    }

    public function test_store_ploi_rejects_invalid_token_and_does_not_persist(): void
    {
        Http::fake([
            'https://ploi.io/api/user' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->set('ploi_api_token', 'ploi_bad_token')
            ->call('storePloi')
            ->assertHasErrors('ploi_api_token');

        $this->assertDatabaseCount('provider_credentials', 0);
    }

    public function test_verify_credential_rechecks_token(): void
    {
        Http::fake([
            'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1]], 200),
        ]);

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_valid_token'],
        ]);

        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->call('verifyCredential', $credential->id)
            ->assertHasNoErrors();
    }
}
