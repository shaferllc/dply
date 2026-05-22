<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Jobs\ProvisionSiteJob;
use App\Services\Imports\Handlers\CreateTargetSiteHandler;
use App\Services\Imports\WaitForTargetServerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CreateTargetSiteHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: Server}
     */
    protected function seedFixture(string $serverStatus = Server::STATUS_READY): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $target = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => $serverStatus,
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'target_server_id' => $target->id,
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_PENDING,
            'source_snapshot' => [
                'repository' => 'acme/app',
                'repository_provider' => 'github',
                'branch' => 'main',
                'web_directory' => '/public',
                'php_version' => '8.3',
            ],
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 10,
            'step_key' => ImportMigrationStep::KEY_CREATE_TARGET_SITE,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        return [$step, $child, $target];
    }

    public function test_creates_site_and_primary_domain_from_source_snapshot(): void
    {
        Bus::fake();
        [$step, $child, $target] = $this->seedFixture();

        ($this->app->make(CreateTargetSiteHandler::class))->execute($step);

        Bus::assertDispatched(ProvisionSiteJob::class);

        $child->refresh();
        $this->assertNotNull($child->target_site_id);
        $this->assertSame(ImportSiteMigration::STATUS_STAGING, $child->status);

        $site = Site::find($child->target_site_id);
        $this->assertNotNull($site);
        $this->assertSame($target->id, $site->server_id);
        $this->assertSame('php', $site->runtime);
        $this->assertSame('8.3', $site->runtime_version);
        $this->assertSame('git@github.com:acme/app.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('/public', $site->document_root);
        $this->assertTrue((bool) $site->laravel_scheduler);

        $domain = SiteDomain::where('site_id', $site->id)->first();
        $this->assertNotNull($domain);
        $this->assertSame('app.example.com', $domain->hostname);
        $this->assertTrue((bool) $domain->is_primary);
    }

    public function test_throws_wait_when_target_server_not_ready(): void
    {
        Bus::fake();
        [$step] = $this->seedFixture(serverStatus: Server::STATUS_PROVISIONING);

        $this->expectException(WaitForTargetServerException::class);
        ($this->app->make(CreateTargetSiteHandler::class))->execute($step);
    }

    public function test_idempotent_when_target_site_id_already_set(): void
    {
        Bus::fake();
        [$step, $child] = $this->seedFixture();
        ($this->app->make(CreateTargetSiteHandler::class))->execute($step);
        $firstSiteId = $child->fresh()->target_site_id;

        // Re-run.
        $step->refresh();
        ($this->app->make(CreateTargetSiteHandler::class))->execute($step);

        $this->assertSame($firstSiteId, $child->fresh()->target_site_id);
        $this->assertSame(1, Site::query()->count(), 'Should not double-create the site');
    }
}
