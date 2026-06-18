<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\EligibilityScanHandlerTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\EligibilityScanHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
test('aborts children whose source site was removed', function () {
    [$migration, $eligible, $removedFromSource] = seedFixture();

    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_ELIGIBILITY_SCAN,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new EligibilityScanHandler)->execute($step);

    expect($eligible->refresh()->status)->toBe(ImportSiteMigration::STATUS_PENDING, 'eligible site stays pending');
    expect($removedFromSource->refresh()->status)->toBe(ImportSiteMigration::STATUS_ABORTED, 'removed-from-source child gets aborted');
    expect($removedFromSource->failure_summary)->not->toBeEmpty();

    $step->refresh();
    expect($step->result_data['children_aborted'])->toBe(1);
});
test('pending steps for aborted child are skipped', function () {
    [$migration, , $removedFromSource] = seedFixture();
    $childStep = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $removedFromSource->id,
        'sequence' => 5,
        'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    $scanStep = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_ELIGIBILITY_SCAN,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new EligibilityScanHandler)->execute($scanStep);

    expect($childStep->refresh()->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
});
/**
 * @return array{0: ImportServerMigration, 1: ImportSiteMigration, 2: ImportSiteMigration}
 */
function seedFixture(): array
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

    // Source-side: site 100 still present, site 101 removed_from_source.
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
        'source_snapshot' => ['repository' => 'acme/a'],
    ]);
    PloiSite::create([
        'ploi_server_id' => $ploiServer->id,
        'source_id' => 101,
        'domain' => 'b.example.com',
        'site_type' => 'laravel',
        'php_version' => '8.3',
        'repository_url' => 'git@github.com:acme/b.git',
        'repository_branch' => 'main',
        'web_directory' => '/public',
        'status' => 'installed',
        'removed_from_source' => true,
        'source_snapshot' => ['repository' => 'acme/b'],
    ]);

    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'status' => ImportServerMigration::STATUS_STAGING,
    ]);
    $eligible = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'a.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_PENDING,
        'source_snapshot' => ['repository' => 'acme/a'],
    ]);
    $removed = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 101,
        'domain' => 'b.example.com',
        'site_type' => 'laravel',
        'status' => ImportSiteMigration::STATUS_PENDING,
        'source_snapshot' => ['repository' => 'acme/b'],
    ]);

    return [$migration, $eligible, $removed];
}
