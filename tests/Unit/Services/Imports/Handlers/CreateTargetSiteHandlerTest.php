<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\CreateTargetSiteHandlerTest;
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
use Illuminate\Support\Facades\Bus;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: Server}
 */
function seedFixture(string $serverStatus = Server::STATUS_READY): array
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
test('creates site and primary domain from source snapshot', function () {
    Bus::fake();
    [$step, $child, $target] = seedFixture();

    ($this->app->make(CreateTargetSiteHandler::class))->execute($step);

    Bus::assertDispatched(ProvisionSiteJob::class);

    $child->refresh();
    expect($child->target_site_id)->not->toBeNull();
    expect($child->status)->toBe(ImportSiteMigration::STATUS_STAGING);

    $site = Site::find($child->target_site_id);
    expect($site)->not->toBeNull();
    expect($site->server_id)->toBe($target->id);
    expect($site->runtime)->toBe('php');
    expect($site->runtime_version)->toBe('8.3');
    expect($site->git_repository_url)->toBe('git@github.com:acme/app.git');
    expect($site->git_branch)->toBe('main');
    expect($site->document_root)->toBe('/public');
    expect((bool) $site->laravel_scheduler)->toBeTrue();

    $domain = SiteDomain::where('site_id', $site->id)->first();
    expect($domain)->not->toBeNull();
    expect($domain->hostname)->toBe('app.example.com');
    expect((bool) $domain->is_primary)->toBeTrue();
});
test('throws wait when target server not ready', function () {
    Bus::fake();
    [$step] = seedFixture(serverStatus: Server::STATUS_PROVISIONING);

    $this->expectException(WaitForTargetServerException::class);
    ($this->app->make(CreateTargetSiteHandler::class))->execute($step);
});
test('idempotent when target site id already set', function () {
    Bus::fake();
    [$step, $child] = seedFixture();
    ($this->app->make(CreateTargetSiteHandler::class))->execute($step);
    $firstSiteId = $child->fresh()->target_site_id;

    // Re-run.
    $step->refresh();
    ($this->app->make(CreateTargetSiteHandler::class))->execute($step);

    expect($child->fresh()->target_site_id)->toBe($firstSiteId);
    expect(Site::query()->count())->toBe(1, 'Should not double-create the site');
});
