<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Livewire\Servers\Create\StepType as ServerCreateStepType;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PloiMigrationWizardTest extends TestCase
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

    protected function ploiServerFor(User $user): PloiServer
    {
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);

        return PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'prod-web-01',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => 's-2vcpu-4gb',
            'php_versions' => ['8.3'],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
    }

    public function test_create_page_shows_migration_banner_when_from_ploi_server_set(): void
    {
        $user = $this->userWithOrganization();
        $user->markEmailAsVerified();
        $ploi = $this->ploiServerFor($user);

        $response = $this->actingAs($user)->get('/servers/create?from_ploi_server='.$ploi->id);

        $response->assertOk()
            ->assertSee('Migrate from Ploi')
            ->assertSee('prod-web-01')
            ->assertSee('Cancel and return to inventory');
    }

    public function test_livewire_mount_captures_migration_source(): void
    {
        $user = $this->userWithOrganization();
        $ploi = $this->ploiServerFor($user);

        Livewire::actingAs($user)
            ->withQueryParams(['from_ploi_server' => $ploi->id])
            ->test(ServerCreateStepType::class)
            ->assertSet('migrationSourcePloiServerId', $ploi->id)
            ->assertSet('migrationSourceLabel', 'prod-web-01');
    }

    public function test_unknown_ploi_server_id_is_ignored(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->withQueryParams(['from_ploi_server' => '01jfake0000000000000000000'])
            ->test(ServerCreateStepType::class)
            ->assertSet('migrationSourcePloiServerId', null);
    }

    public function test_ploi_server_owned_by_another_org_is_ignored(): void
    {
        // Set up org-A user with the PloiServer.
        $otherUser = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        $otherOrg->users()->attach($otherUser->id, ['role' => 'owner']);
        $otherCredential = ProviderCredential::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);
        $ploi = PloiServer::create([
            'provider_credential_id' => $otherCredential->id,
            'source_id' => 99,
            'name' => 'someone-elses-server',
            'ip_address' => null,
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => [],
            'status' => null,
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);

        // Switch to a different user in a different org.
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->withQueryParams(['from_ploi_server' => $ploi->id])
            ->test(ServerCreateStepType::class)
            ->assertSet('migrationSourcePloiServerId', null);
    }
}
