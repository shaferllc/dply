<?php

namespace Tests\Feature;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialTest extends TestCase
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

    public function test_credentials_index_redirects_guest(): void
    {
        $response = $this->get(route('credentials.index'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_credentials_index_is_displayed(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('credentials.index'));

        $response->assertOk();
        $response->assertSee('Provider credentials');
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
}
