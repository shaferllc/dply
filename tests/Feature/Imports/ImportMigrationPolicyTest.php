<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Models\ImportMigrationStep;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Q18 policy: migrate-import is owners + admins only. Deployers see a 403
 * and can't kick off migrations even though they can create servers.
 */
class ImportMigrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: ImportServerMigration}
     */
    protected function seedDeployerMigration(): array
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $credential = ProviderCredential::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);

        $deployer = User::factory()->create();
        $org->users()->attach($deployer->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        return [$deployer, $org, $migration];
    }

    public function test_deployer_cannot_view_migration_progress_page(): void
    {
        [$deployer, , $migration] = $this->seedDeployerMigration();

        $this->actingAs($deployer)
            ->get(route('imports.ploi.migration.progress', $migration))
            ->assertForbidden();
    }

    public function test_deployer_mount_on_progress_page_aborts_403(): void
    {
        Bus::fake();
        [$deployer, , $migration] = $this->seedDeployerMigration();

        // Livewire mount runs authorize → abort(403); test that via HTTP since
        // the Livewire harness can't observe the abort cleanly.
        $this->actingAs($deployer)
            ->get(route('imports.ploi.migration.progress', $migration))
            ->assertForbidden();

        Bus::assertNotDispatched(RunMigrationStepJob::class);
    }

    public function test_admin_can_begin_cutover(): void
    {
        Bus::fake();
        [, $org, $migration] = $this->seedDeployerMigration();
        $admin = User::factory()->create();
        $org->users()->attach($admin->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);

        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
            'source_snapshot' => [],
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 50,
            'step_key' => ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        Livewire::actingAs($admin)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('beginCutover', $child->id)
            ->assertHasNoErrors();

        Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($step): bool {
            return $job->stepId === $step->id;
        });
    }

    public function test_kickoff_helper_returns_null_when_user_lacks_admin_role(): void
    {
        Bus::fake();
        // Set up a deployer-role user in an org with a ploi server.
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'srv',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => [],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        PloiSite::create([
            'ploi_server_id' => $ploiServer->id,
            'source_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/app.git',
            'repository_branch' => 'main',
            'web_directory' => '/public',
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => ['repository' => 'acme/app'],
        ]);

        $stepReview = $this->app->make(\App\Livewire\Servers\Create\StepReview::class);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $ref = new \ReflectionMethod($stepReview, 'kickOffPloiMigration');
        $ref->setAccessible(true);
        $migration = $ref->invoke($stepReview, $ploiServer->id, $target, $user);

        $this->assertNull($migration);
        Bus::assertNotDispatched(RunMigrationStepJob::class);
        $this->assertSame(0, ImportServerMigration::query()->count());
    }
}
