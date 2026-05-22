<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\ForgeMigrationWizardTest;
use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Servers\Create\StepReview;
use App\Livewire\Servers\Create\StepType as ServerCreateStepType;
use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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
/**
 * @return array{0: User, 1: Organization, 2: ForgeServer, 3: array<int, ForgeSite>}
 */
function seedForgeFleet(): array
{
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'forge',
    ]);
    $forgeServer = ForgeServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'agency-prod',
        'ip_address' => '203.0.113.10',
        'provider_label' => 'digitalocean',
        'server_type' => 's-2vcpu-4gb',
        'php_versions' => ['8.3'],
        'status' => 'active',
        'last_synced_at' => now(),
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);
    $sites = [
        ForgeSite::create([
            'forge_server_id' => $forgeServer->id, 'source_id' => 100, 'domain' => 'app.example.com',
            'site_type' => 'laravel', 'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/app.git', 'repository_branch' => 'main',
            'web_directory' => '/public', 'status' => 'installed',
            'removed_from_source' => false, 'source_snapshot' => ['repository' => 'acme/app'],
        ]),
        ForgeSite::create([
            'forge_server_id' => $forgeServer->id, 'source_id' => 101, 'domain' => 'static.example.com',
            'site_type' => 'static', 'php_version' => null,
            'repository_url' => null, 'repository_branch' => null,
            'web_directory' => null, 'status' => 'installed',
            'removed_from_source' => false, 'source_snapshot' => null,
        ]),
    ];

    return [$user, $org, $forgeServer, $sites];
}
test('step type captures forge source from query param', function () {
    [$user, , $forgeServer] = seedForgeFleet();

    Livewire::actingAs($user)
        ->withQueryParams(['from_forge_server' => $forgeServer->id])
        ->test(ServerCreateStepType::class)
        ->assertSet('migrationSourceForgeServerId', $forgeServer->id)
        ->assertSet('migrationSourceLabel', 'agency-prod')
        ->assertSet('migrationSourceKind', 'forge');
});
test('step review hydrates forge selection from draft', function () {
    [$user, $org, $forgeServer, $sites] = seedForgeFleet();
    ServerCreateDraft::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 4,
        'payload' => [
            '_forge_migration_source_id' => $forgeServer->id,
            'name' => 'dply-target',
            'mode' => 'custom',
            'type' => 'custom',
        ],
        'expires_at' => now()->addDays(14),
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->assertSet('migrationSourceForgeServerId', $forgeServer->id)
        ->assertSet('migrationSourceKind', 'forge')
        ->assertSet('migrationSiteSelection.'.$sites[0]->id, true)
        ->assertSet('migrationSiteSelection.'.$sites[1]->id, false);
});
test('kickoff forge creates migration with source forge', function () {
    Bus::fake();
    [$user, $org, $forgeServer] = seedForgeFleet();

    $stepReview = $this->app->make(StepReview::class);
    $target = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $ref = new \ReflectionMethod($stepReview, 'kickOffForgeMigration');
    $ref->setAccessible(true);
    $migration = $ref->invoke($stepReview, $forgeServer->id, $target, $user, null);

    expect($migration)->toBeInstanceOf(ImportServerMigration::class);
    expect($migration->source)->toBe('forge');
    expect($migration->source_server_id)->toBe(42);
    expect($migration->target_server_id)->toBe($target->id);
    expect($migration->siteMigrations()->count())->toBe(1, 'static site excluded by eligibility filter');

    Bus::assertDispatched(RunMigrationStepJob::class, 1);
});
test('step review persists forge selection into draft on toggle', function () {
    [$user, $org, $forgeServer, $sites] = seedForgeFleet();
    ServerCreateDraft::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 4,
        'payload' => [
            '_forge_migration_source_id' => $forgeServer->id,
            'name' => 'dply-target',
            'mode' => 'custom',
            'type' => 'custom',
        ],
        'expires_at' => now()->addDays(14),
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->set('migrationSiteSelection.'.$sites[0]->id, false);

    $draft = ServerCreateDraft::query()->where('user_id', $user->id)->first();
    expect($draft)->not->toBeNull();
    $selection = $draft->payload['_forge_migration_site_selection'] ?? null;
    expect($selection)->toBeArray();
    expect($selection[$sites[0]->id])->toBeFalse();
});
