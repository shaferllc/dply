<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Servers\Create\StepReview;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PloiWizardChecklistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: PloiServer, 3: array<int, PloiSite>}
     */
    protected function seedFixture(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'prod-web-01',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => ['8.3'],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        $sites = [
            PloiSite::create([
                'ploi_server_id' => $ploiServer->id, 'source_id' => 100, 'domain' => 'a.example.com',
                'site_type' => 'laravel', 'php_version' => '8.3',
                'repository_url' => 'git@github.com:acme/a.git', 'repository_branch' => 'main',
                'web_directory' => '/public', 'status' => 'installed',
                'removed_from_source' => false, 'source_snapshot' => ['repository' => 'acme/a'],
            ]),
            PloiSite::create([
                'ploi_server_id' => $ploiServer->id, 'source_id' => 101, 'domain' => 'b.example.com',
                'site_type' => 'php', 'php_version' => '8.3',
                'repository_url' => 'git@github.com:acme/b.git', 'repository_branch' => 'main',
                'web_directory' => '/public', 'status' => 'installed',
                'removed_from_source' => false, 'source_snapshot' => ['repository' => 'acme/b'],
            ]),
            PloiSite::create([
                'ploi_server_id' => $ploiServer->id, 'source_id' => 102, 'domain' => 'wp.example.com',
                'site_type' => 'wordpress', 'php_version' => '8.3',
                'repository_url' => null, 'repository_branch' => null,
                'web_directory' => null, 'status' => 'installed',
                'removed_from_source' => false, 'source_snapshot' => null,
            ]),
        ];

        return [$user, $org, $ploiServer, $sites];
    }

    public function test_mount_defaults_eligible_sites_checked_and_unsupported_unchecked(): void
    {
        [$user, $org, $ploiServer, $sites] = $this->seedFixture();
        ServerCreateDraft::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'step' => 4,
            'payload' => [
                '_ploi_migration_source_id' => $ploiServer->id,
                'name' => 'dply-target',
                'mode' => 'custom',
                'type' => 'custom',
            ],
            'expires_at' => now()->addDays(14),
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test(StepReview::class)
            ->assertSet('migrationSourcePloiServerId', $ploiServer->id)
            ->assertSet('migrationSiteSelection.'.$sites[0]->id, true)
            ->assertSet('migrationSiteSelection.'.$sites[1]->id, true)
            ->assertSet('migrationSiteSelection.'.$sites[2]->id, false);
    }

    public function test_toggling_selection_persists_into_draft_payload(): void
    {
        [$user, $org, $ploiServer, $sites] = $this->seedFixture();
        ServerCreateDraft::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'step' => 4,
            'payload' => [
                '_ploi_migration_source_id' => $ploiServer->id,
                'name' => 'dply-target',
                'mode' => 'custom',
                'type' => 'custom',
            ],
            'expires_at' => now()->addDays(14),
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test(StepReview::class)
            ->set('migrationSiteSelection.'.$sites[1]->id, false);

        $draft = ServerCreateDraft::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($draft);
        $selection = $draft->payload['_ploi_migration_site_selection'] ?? null;
        $this->assertIsArray($selection);
        $this->assertTrue($selection[$sites[0]->id]);
        $this->assertFalse($selection[$sites[1]->id]);
    }

    public function test_kickoff_honors_explicit_selection_and_filters_to_eligible(): void
    {
        Bus::fake();
        [$user, $org, $ploiServer, $sites] = $this->seedFixture();

        $stepReview = $this->app->make(StepReview::class);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $ref = new \ReflectionMethod($stepReview, 'kickOffPloiMigration');
        $ref->setAccessible(true);
        // User-explicit selection: site[0] checked, site[1] unchecked, site[2]
        // (wordpress) was "checked" but should be filtered out by the eligibility intersect.
        $migration = $ref->invoke($stepReview, $ploiServer->id, $target, $user, [
            $sites[0]->id => true,
            $sites[1]->id => false,
            $sites[2]->id => true,
        ]);

        $this->assertInstanceOf(ImportServerMigration::class, $migration);
        $this->assertSame(1, $migration->siteMigrations()->count());
        $this->assertSame('a.example.com', $migration->siteMigrations->first()->domain);
        Bus::assertDispatched(RunMigrationStepJob::class, 1);
    }

    public function test_kickoff_falls_back_to_all_eligible_when_no_selection_provided(): void
    {
        Bus::fake();
        [$user, $org, $ploiServer] = $this->seedFixture();

        $stepReview = $this->app->make(StepReview::class);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $ref = new \ReflectionMethod($stepReview, 'kickOffPloiMigration');
        $ref->setAccessible(true);
        $migration = $ref->invoke($stepReview, $ploiServer->id, $target, $user, null);

        $this->assertInstanceOf(ImportServerMigration::class, $migration);
        // Both eligible sites land — wordpress excluded by eligibility filter.
        $this->assertSame(2, $migration->siteMigrations()->count());
    }
}
