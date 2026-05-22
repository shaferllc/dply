<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\PloiMigrationWizardTest;
use App\Livewire\Servers\Create\StepType as ServerCreateStepType;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\User;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
function ploiServerFor(User $user): PloiServer
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
test('create page shows migration banner when from ploi server set', function () {
    $user = userWithOrganization();
    $user->markEmailAsVerified();
    $ploi = ploiServerFor($user);

    $response = $this->actingAs($user)->get('/servers/create?from_ploi_server='.$ploi->id);

    $response->assertOk()
        ->assertSee('Migrate from Ploi')
        ->assertSee('prod-web-01')
        ->assertSee('Cancel and return to inventory');
});
test('livewire mount captures migration source', function () {
    $user = userWithOrganization();
    $ploi = ploiServerFor($user);

    Livewire::actingAs($user)
        ->withQueryParams(['from_ploi_server' => $ploi->id])
        ->test(ServerCreateStepType::class)
        ->assertSet('migrationSourcePloiServerId', $ploi->id)
        ->assertSet('migrationSourceLabel', 'prod-web-01');
});
test('unknown ploi server id is ignored', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->withQueryParams(['from_ploi_server' => '01jfake0000000000000000000'])
        ->test(ServerCreateStepType::class)
        ->assertSet('migrationSourcePloiServerId', null);
});
test('ploi server owned by another org is ignored', function () {
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
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->withQueryParams(['from_ploi_server' => $ploi->id])
        ->test(ServerCreateStepType::class)
        ->assertSet('migrationSourcePloiServerId', null);
});
