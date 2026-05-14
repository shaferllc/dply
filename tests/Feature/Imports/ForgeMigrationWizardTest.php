<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class ForgeMigrationWizardTest extends TestCase
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

    /**
     * @return array{0: User, 1: Organization, 2: ForgeServer, 3: array<int, ForgeSite>}
     */
    protected function seedForgeFleet(): array
    {
        $user = $this->userWithOrganization();
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

    public function test_step_type_captures_forge_source_from_query_param(): void
    {
        [$user, , $forgeServer] = $this->seedForgeFleet();

        Livewire::actingAs($user)
            ->withQueryParams(['from_forge_server' => $forgeServer->id])
            ->test(ServerCreateStepType::class)
            ->assertSet('migrationSourceForgeServerId', $forgeServer->id)
            ->assertSet('migrationSourceLabel', 'agency-prod')
            ->assertSet('migrationSourceKind', 'forge');
    }

    public function test_step_review_hydrates_forge_selection_from_draft(): void
    {
        [$user, $org, $forgeServer, $sites] = $this->seedForgeFleet();
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
    }

    public function test_kickoff_forge_creates_migration_with_source_forge(): void
    {
        Bus::fake();
        [$user, $org, $forgeServer] = $this->seedForgeFleet();

        $stepReview = $this->app->make(StepReview::class);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $ref = new \ReflectionMethod($stepReview, 'kickOffForgeMigration');
        $ref->setAccessible(true);
        $migration = $ref->invoke($stepReview, $forgeServer->id, $target, $user, null);

        $this->assertInstanceOf(ImportServerMigration::class, $migration);
        $this->assertSame('forge', $migration->source);
        $this->assertSame(42, $migration->source_server_id);
        $this->assertSame($target->id, $migration->target_server_id);
        $this->assertSame(1, $migration->siteMigrations()->count(), 'static site excluded by eligibility filter');

        Bus::assertDispatched(RunMigrationStepJob::class, 1);
    }

    public function test_step_review_persists_forge_selection_into_draft_on_toggle(): void
    {
        [$user, $org, $forgeServer, $sites] = $this->seedForgeFleet();
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
        $this->assertNotNull($draft);
        $selection = $draft->payload['_forge_migration_site_selection'] ?? null;
        $this->assertIsArray($selection);
        $this->assertFalse($selection[$sites[0]->id]);
    }
}
