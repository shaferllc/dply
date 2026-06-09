<?php

namespace Tests\Unit\Services\ServerPhpSiteRuntimeMigratorTest;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerPhpSiteRuntimeMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeMigratorServer(array $meta = []): Server
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'owner']);

    return Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => $meta,
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
}

it('resolves the newest installed version other than the source version', function () {
    $migrator = app(ServerPhpSiteRuntimeMigrator::class);

    expect($migrator->resolveMigrationTargetVersion(['8.3', '8.4', '8.5'], '8.3'))->toBe('8.5')
        ->and($migrator->resolveMigrationTargetVersion(['8.3', '8.4'], '8.4'))->toBe('8.3')
        ->and($migrator->resolveMigrationTargetVersion(['8.3'], '8.3'))->toBeNull();
});

it('moves php sites to another installed version and queues webserver applies', function () {
    Queue::fake();

    $server = makeMigratorServer([
        'server_role' => 'application',
        'php_inventory' => [
            'supported' => true,
            'installed_versions' => ['8.4', '8.3'],
            'detected_default_version' => '8.4',
        ],
    ]);

    $user = User::factory()->create();
    $siteA = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
    ]);
    $siteB = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
    ]);

    $summary = app(ServerPhpSiteRuntimeMigrator::class)->migrateSitesUsingVersion($server, '8.3', '8.4', $user->id);

    expect($summary['migrated_count'])->toBe(2)
        ->and($summary['target_version'])->toBe('8.4')
        ->and($siteA->fresh()->runtime_version)->toBe('8.4')
        ->and($siteB->fresh()->runtime_version)->toBe('8.4');

    Queue::assertPushed(ApplySiteWebserverConfigJob::class, 2);
});

it('migrates sites before uninstall when requested', function () {
    Queue::fake();

    $server = makeMigratorServer([
        'server_role' => 'application',
        'php_inventory' => [
            'supported' => true,
            'installed_versions' => ['8.4', '8.3'],
            'detected_default_version' => '8.4',
        ],
        'default_php_version' => '8.4',
        'php_new_site_default_version' => '8.4',
    ]);

    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
    ]);

    $manager = \Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $manager->shouldReceive('fetchRemoteInventory')
        ->once()
        ->andReturn([
            'supported' => true,
            'installed_versions' => ['8.4', '8.3'],
            'detected_default_version' => '8.4',
        ]);
    $manager->shouldReceive('executePackageAction')->once();
    $manager->shouldReceive('fetchRemoteInventory')
        ->once()
        ->andReturn([
            'supported' => true,
            'installed_versions' => ['8.4'],
            'detected_default_version' => '8.4',
        ]);

    $result = $manager->applyPackageAction($server, 'uninstall', '8.3', null, true, null);

    expect($result['status'])->toBe('succeeded');
    expect($server->sites()->where('runtime', 'php')->where('runtime_version', '8.3')->count())->toBe(0);
    Queue::assertPushed(ApplySiteWebserverConfigJob::class, 1);
});
