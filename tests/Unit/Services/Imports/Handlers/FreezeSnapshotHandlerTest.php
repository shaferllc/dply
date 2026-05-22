<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\Handlers\FreezeSnapshotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreezeSnapshotHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_copies_current_ploi_site_snapshot_onto_child(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);
        $ploiServer = PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'srv',
            'ip_address' => null,
            'provider_label' => null,
            'server_type' => null,
            'php_versions' => [],
            'status' => null,
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        PloiSite::create([
            'ploi_server_id' => $ploiServer->id,
            'source_id' => 100,
            'domain' => 'a.example.com',
            'site_type' => 'laravel',
            'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/a.git',
            'repository_branch' => 'main',
            'web_directory' => '/public',
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => ['env' => ['APP_ENV' => 'production']],
        ]);

        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'a.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_PENDING,
            'source_snapshot' => ['old' => 'snapshot'],
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 5,
            'step_key' => ImportMigrationStep::KEY_FREEZE_SNAPSHOT,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        (new FreezeSnapshotHandler())->execute($step);

        $child->refresh();
        $this->assertSame(['env' => ['APP_ENV' => 'production']], $child->source_snapshot);
    }

    public function test_throws_when_site_scoped_step_lacks_site_id(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_STAGING,
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 5,
            'step_key' => ImportMigrationStep::KEY_FREEZE_SNAPSHOT,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        $this->expectException(\RuntimeException::class);
        (new FreezeSnapshotHandler())->execute($step);
    }
}
