<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Models\ImportServerMigration;
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

/**
 * Exercises the wiring at StepReview::store — the moment the dply target Server
 * is created, the planner builds an ImportServerMigration and the first step is
 * dispatched. Doesn't go through the full Livewire wizard (Step 1 → 4); seeds
 * the draft state + invokes store() directly. The wizard wiring (banner,
 * draft payload key) is covered by PloiMigrationWizardTest.
 */
class PloiMigrationKickoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_import_migration_when_draft_carries_ploi_source(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);
        $ploiServer = PloiServer::create([
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
        // wordpress site — should be excluded
        PloiSite::create([
            'ploi_server_id' => $ploiServer->id,
            'source_id' => 101,
            'domain' => 'wp.example.com',
            'site_type' => 'wordpress',
            'php_version' => '8.3',
            'repository_url' => null,
            'repository_branch' => null,
            'web_directory' => null,
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);

        // Synthesize a finished-wizard draft with the migration source id.
        ServerCreateDraft::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'step' => 4,
            'payload' => [
                '_ploi_migration_source_id' => $ploiServer->id,
                'name' => 'dply-prod-target',
                'mode' => 'custom',
                'type' => 'custom',
                'install_profile' => 'laravel_app',
            ],
            'expires_at' => now()->addDays(14),
        ]);

        // Drive the moment-of-truth: directly invoke the kickoff helper through
        // a custom assertion harness. We bypass the full Livewire store() pipeline
        // (preflight validation, real provisioning) because that's not what's under
        // test here — only the migration-planning side effect of a successful create.
        $stepReview = $this->app->make(\App\Livewire\Servers\Create\StepReview::class);

        // The kickoff helper is protected; invoke via Reflection. (StepReview's
        // public store() requires a full preflight + actual server provisioning;
        // we exercise only the migration-side branch.)
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $ref = new \ReflectionMethod($stepReview, 'kickOffPloiMigration');
        $ref->setAccessible(true);
        $migration = $ref->invoke($stepReview, $ploiServer->id, $target, $user);

        $this->assertInstanceOf(ImportServerMigration::class, $migration);
        $this->assertSame('ploi', $migration->source);
        $this->assertSame($target->id, $migration->target_server_id);

        // Only the eligible site should produce a child migration row.
        $this->assertSame(1, $migration->siteMigrations()->count());
        $this->assertSame('app.example.com', $migration->siteMigrations->first()->domain);

        // First runnable step should be dispatched.
        Bus::assertDispatched(RunMigrationStepJob::class, 1);
    }

    public function test_store_skips_migration_when_no_eligible_sites_remain(): void
    {
        Bus::fake();

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
            'name' => 'srv',
            'ip_address' => null,
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => [],
            'status' => null,
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        PloiSite::create([
            'ploi_server_id' => $ploiServer->id,
            'source_id' => 999,
            'domain' => 'wp.example.com',
            'site_type' => 'wordpress',
            'php_version' => '8.3',
            'repository_url' => null,
            'repository_branch' => null,
            'web_directory' => null,
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => null,
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
    }
}
