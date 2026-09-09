<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\DatabaseCreateAdoptsBindingTest;

use App\Jobs\CreateSiteDatabaseJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The mirror of the Database-tab bug: a database created for a site set
 * server_databases.site_id but no binding, so it appeared on the Database tab
 * and nowhere else. Creating one now adopts it as a `database` binding, which
 * becomes the single source for DB_*.
 *
 * @return array{0: Server, 1: Site}
 */
function adoptFixture(?string $envFile = null): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'env_file_content' => $envFile,
    ]);

    return [$server, $site];
}

function newDb(Server $server, Site $site, string $name = 'databio'): ServerDatabase
{
    return ServerDatabase::query()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'name' => $name,
        'engine' => 'postgres',
        'username' => $name.'_u',
        'password' => 'sekret',
        'host' => '127.0.0.1',
    ]);
}

test('adopting a new database creates a primary binding that injects DB_*', function () {
    [$server, $site] = adoptFixture();
    $db = newDb($server, $site);

    $binding = app(SiteBindingManager::class)->adoptServerDatabase($site, $db);

    expect($binding)->not->toBeNull()
        ->and($binding->type)->toBe('database')
        ->and($binding->name)->toBe('primary')
        ->and($binding->target_type)->toBe('server_database')
        ->and((string) $binding->target_id)->toBe((string) $db->id)
        // Still provisioning — CreateSiteDatabaseJob flips it once CREATE
        // DATABASE actually succeeds on the host.
        ->and($binding->status)->toBe(SiteBinding::STATUS_PROVISIONING);

    expect($binding->connectionEnv())
        ->toHaveKey('DB_CONNECTION', 'pgsql')
        ->toHaveKey('DB_DATABASE', 'databio')
        ->toHaveKey('DB_USERNAME', 'databio_u')
        ->toHaveKey('DATABASE_URL');
});

test('adoption is skipped when a primary database binding already points elsewhere', function () {
    [$server, $site] = adoptFixture();
    $first = newDb($server, $site, 'first');
    app(SiteBindingManager::class)->adoptServerDatabase($site, $first);

    // A second database must not silently repoint the running app.
    $second = newDb($server, $site, 'second');
    $binding = app(SiteBindingManager::class)->adoptServerDatabase($site->fresh(), $second);

    expect($binding)->toBeNull()
        ->and($site->fresh()->bindings()->where('type', 'database')->count())->toBe(1);
});

test('adopting the same database twice updates one binding rather than adding another', function () {
    [$server, $site] = adoptFixture();
    $db = newDb($server, $site);

    $a = app(SiteBindingManager::class)->adoptServerDatabase($site, $db);
    $b = app(SiteBindingManager::class)->adoptServerDatabase($site->fresh(), $db);

    expect((string) $b->id)->toBe((string) $a->id)
        ->and($site->fresh()->bindings()->where('type', 'database')->count())->toBe(1);
});

test('adopted keys are stripped from the editable env so they are not inert overrides', function () {
    // A scaffold left stale DB_* behind; the binding beats them at push time,
    // so leaving them would show 5 overrides that never apply.
    [$server, $site] = adoptFixture(
        "APP_NAME=dply\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_USERNAME=root\nMAIL_MAILER=log\n"
    );
    $db = newDb($server, $site);

    $manager = app(SiteBindingManager::class);
    $binding = $manager->adoptServerDatabase($site, $db);
    $manager->stripAdoptedEnvKeys($site, $binding);

    $env = (string) $site->fresh()->env_file_content;

    expect($env)
        ->toContain('APP_NAME=dply')
        ->toContain('MAIL_MAILER=log')
        ->not->toContain('DB_CONNECTION=mysql')
        ->not->toContain('DB_USERNAME=root');
});

test('stripping leaves a site with no env cache alone', function () {
    [$server, $site] = adoptFixture();
    $db = newDb($server, $site);
    $manager = app(SiteBindingManager::class);

    $manager->stripAdoptedEnvKeys($site, $manager->adoptServerDatabase($site, $db));

    expect($site->fresh()->env_file_content)->toBeNull();
});

test('the create job pushes even when the binding owns the keys', function () {
    // writeEnv=false (a binding owns DB_*) used to also disable the push,
    // because the dispatch lived inside injectEnv().
    $job = new CreateSiteDatabaseJob('db-id', 'site-id', writeEnv: false, pushEnv: true);

    expect($job->writeEnv)->toBeFalse()
        ->and($job->pushEnv)->toBeTrue();

    $ref = new \ReflectionMethod(CreateSiteDatabaseJob::class, 'pushEnvToServer');

    expect($ref->isPrivate())->toBeTrue();
});

test('a database created at site create is visible as a resource binding', function () {
    Queue::fake();

    [$server, $site] = adoptFixture();
    $db = newDb($server, $site);

    $binding = app(SiteBindingManager::class)->adoptServerDatabase($site, $db);

    // Both surfaces now agree: owned by site_id AND reachable as a binding.
    expect($site->fresh()->serverDatabases()->pluck('name')->all())->toBe(['databio'])
        ->and($site->fresh()->bindings()->where('type', 'database')->first()?->target_id)
        ->toBe((string) $db->id)
        ->and($binding->connectionEnv())->toHaveKey('DB_DATABASE', 'databio');
});
