<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\MigrationPlannerTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Imports\Services\MigrationPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: ProviderCredential, 1: PloiServer, 2: list<PloiSite>, 3: User, 4: Organization}
 */
function fixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);
    $server = PloiServer::create([
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
    $sites = [
        PloiSite::create([
            'ploi_server_id' => $server->id,
            'source_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/app.git',
            'repository_branch' => 'main',
            'web_directory' => '/public',
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => ['kind' => 'laravel'],
        ]),
        PloiSite::create([
            'ploi_server_id' => $server->id,
            'source_id' => 101,
            'domain' => 'api.example.com',
            'site_type' => 'php',
            'php_version' => '8.3',
            'repository_url' => 'git@github.com:acme/api.git',
            'repository_branch' => 'main',
            'web_directory' => '/public',
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => ['kind' => 'php'],
        ]),
        PloiSite::create([
            'ploi_server_id' => $server->id,
            'source_id' => 102,
            'domain' => 'wp.example.com',
            'site_type' => 'wordpress',
            'php_version' => '8.3',
            'repository_url' => null,
            'repository_branch' => null,
            'web_directory' => null,
            'status' => 'installed',
            'removed_from_source' => false,
            'source_snapshot' => ['kind' => 'wordpress'],
        ]),
    ];

    return [$credential, $server, $sites, $user, $org];
}
function targetServerId(Organization $org, User $user): string
{
    return Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ])->id;
}
test('plan creates parent children and steps for eligible sites', function () {
    [$credential, $server, $sites, $user, $org] = fixture();
    $targetId = targetServerId($org, $user);

    $parent = (new MigrationPlanner)->plan(
        source: $server,
        selectedSiteIds: [$sites[0]->id, $sites[1]->id],
        targetServerId: $targetId,
        credential: $credential,
        userId: $user->id,
    );

    expect($parent)->toBeInstanceOf(ImportServerMigration::class);
    expect($parent->source)->toBe('ploi');
    expect($parent->source_server_id)->toBe(42);
    expect($parent->target_server_id)->toBe($targetId);
    expect($parent->status)->toBe(ImportServerMigration::STATUS_PENDING);

    $children = ImportSiteMigration::query()->where('import_server_migration_id', $parent->id)->get();
    expect($children)->toHaveCount(2);
    expect($children->pluck('domain')->all())->toEqualCanonicalizing(['app.example.com', 'api.example.com']);

    $stepsPerSite = count(MigrationPlanner::STAGING_STEPS) + count(MigrationPlanner::CUTOVER_STEPS);
    $serverSteps = 4;
    // push_ssh_key, eligibility_scan, collect_manual_review, revoke_ssh_key
    $expectedTotal = $serverSteps + (2 * $stepsPerSite);
    expect(ImportMigrationStep::query()->where('import_server_migration_id', $parent->id)->count())->toBe($expectedTotal);
});
test('plan emits server steps first and revoke last', function () {
    [$credential, $server, $sites, $user, $org] = fixture();
    $targetId = targetServerId($org, $user);

    $parent = (new MigrationPlanner)->plan(
        source: $server,
        selectedSiteIds: [$sites[0]->id],
        targetServerId: $targetId,
        credential: $credential,
        userId: $user->id,
    );

    $orderedKeys = ImportMigrationStep::query()
        ->where('import_server_migration_id', $parent->id)
        ->orderBy('sequence')
        ->pluck('step_key')
        ->all();

    expect($orderedKeys[0])->toBe(ImportMigrationStep::KEY_PUSH_SSH_KEY);
    expect($orderedKeys[1])->toBe(ImportMigrationStep::KEY_ELIGIBILITY_SCAN);
    expect(end($orderedKeys))->toBe(ImportMigrationStep::KEY_REVOKE_SSH_KEY);
});
test('plan rejects ineligible sites', function () {
    [$credential, $server, $sites, $user, $org] = fixture();
    $targetId = targetServerId($org, $user);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/wordpress/i');

    (new MigrationPlanner)->plan(
        source: $server,
        selectedSiteIds: [$sites[2]->id], // wordpress
        targetServerId: $targetId,
        credential: $credential,
        userId: $user->id,
    );
});
test('plan rejects empty selection', function () {
    [$credential, $server, , $user, $org] = fixture();
    $targetId = targetServerId($org, $user);

    $this->expectException(\RuntimeException::class);
    (new MigrationPlanner)->plan(
        source: $server,
        selectedSiteIds: [],
        targetServerId: $targetId,
        credential: $credential,
        userId: $user->id,
    );
});
