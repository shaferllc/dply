<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q19 onboarding integration: when the user has no servers yet but has
 * connected a Ploi/Forge credential, the Servers index empty state surfaces
 * a "Migrate from Ploi" CTA alongside Create Server.
 */
class OnboardingEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_empty_state_shows_migrate_cta_when_ploi_credential_connected(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);

        $this->actingAs($user)
            ->get('/servers')
            ->assertOk()
            ->assertSee('Migrate from Ploi');
    }

    public function test_empty_state_omits_migrate_cta_when_no_import_credential(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        // Only a compute credential — should NOT trigger the migrate CTA.
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
        ]);

        $this->actingAs($user)
            ->get('/servers')
            ->assertOk()
            ->assertDontSee('Migrate from Ploi');
    }

    public function test_empty_state_shows_migrate_cta_when_only_forge_credential_present(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'forge',
        ]);

        $this->actingAs($user)
            ->get('/servers')
            ->assertOk()
            ->assertSee('Migrate from Ploi');
    }
}
