<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CreateSiteWithDatabaseTest;

use App\Jobs\CreateSiteDatabaseJob;
use App\Jobs\ProvisionSiteJob;
use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<array{engine: string, version: string, default: bool}>  $engines
 * @return array{0: User, 1: Server}
 */
function serverWithEngines(array $engines): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4'],
                'detected_default_version' => '8.4',
            ],
            'php_new_site_default_version' => '8.4',
        ],
    ]);

    foreach ($engines as $engine) {
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => $engine['engine'],
            'version' => $engine['version'],
            'is_default' => $engine['default'],
            'status' => ServerDatabaseEngine::STATUS_RUNNING,
            'port' => ServerDatabaseEngine::defaultPortFor($engine['engine']),
        ]);
    }

    return [$user, $server];
}

test('a single-engine server creates the database on that engine without asking', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        // The engine question is not asked — one engine, nothing to choose.
        ->assertSet('form.database_engine', 'postgres')
        ->assertSet('form.create_database', true)
        ->set('form.name', 'Billing App')
        // Name suggestion follows the site name until the user edits it.
        ->assertSet('form.database_name', 'billing_app')
        ->set('form.primary_hostname', 'billing.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasNoErrors()
        ->assertRedirect();

    $site = Site::query()->where('name', 'Billing App')->firstOrFail();
    $db = ServerDatabase::query()->where('site_id', $site->id)->firstOrFail();

    expect($db->name)->toBe('billing_app')
        ->and($db->engine)->toBe('postgres')
        ->and($db->host)->toBe('127.0.0.1')
        ->and($db->username)->not->toBe('')
        ->and($db->password)->not->toBe('');

    Queue::assertPushed(ProvisionSiteJob::class);
    Queue::assertPushed(CreateSiteDatabaseJob::class, function (CreateSiteDatabaseJob $job) use ($db, $site): bool {
        // The database is adopted as a resource binding, so the BINDING owns
        // DB_* and supplies them at push/deploy — writing them to the editable
        // cache as well would leave inert overrides. push-env stays off so we
        // don't race the provisioner into the site directory.
        return $job->serverDatabaseId === $db->id
            && $job->siteId === $site->id
            && $job->writeEnv === false
            && $job->pushEnv === false
            && $job->siteBindingId !== null;
    });
});

test('the new site database is also attached as a resource binding', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'Bound App')
        ->set('form.primary_hostname', 'bound.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasNoErrors();

    $site = Site::query()->where('name', 'Bound App')->firstOrFail();
    $db = ServerDatabase::query()->where('site_id', $site->id)->firstOrFail();
    $binding = $site->bindings()->where('type', 'database')->first();

    // Both surfaces agree from the moment the site exists: the Database tab
    // sees it via site_id, the resource map via the binding.
    expect($binding)->not->toBeNull()
        ->and((string) $binding->target_id)->toBe((string) $db->id)
        ->and($binding->connectionEnv())->toHaveKey('DB_DATABASE', 'bound_app');
});

test('a multi-engine server honours the engine and name the user picks', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
        ['engine' => 'mysql', 'version' => '8.4', 'default' => false],
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSet('form.database_engine', 'postgres')
        ->set('form.name', 'Reports')
        ->set('form.database_engine', 'mysql')
        ->set('form.database_name', 'Reports Warehouse')
        // The name is sanitized to a legal database identifier as it is typed.
        ->assertSet('form.database_name', 'reports_warehouse')
        // ...and editing it latches, so the site name no longer overwrites it.
        ->set('form.name', 'Reports Renamed')
        ->assertSet('form.database_name', 'reports_warehouse')
        ->set('form.primary_hostname', 'reports.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasNoErrors();

    $site = Site::query()->where('name', 'Reports Renamed')->firstOrFail();
    $db = ServerDatabase::query()->where('site_id', $site->id)->firstOrFail();

    expect($db->engine)->toBe('mysql')
        ->and($db->name)->toBe('reports_warehouse');
});

test('a name already tracked on the server is rejected instead of creating the site', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
    ]);

    ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'taken_db',
        'engine' => 'postgres',
        'username' => 'taken',
        'password' => 'secret',
        'host' => '127.0.0.1',
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'Another App')
        ->set('form.database_name', 'taken_db')
        ->set('form.primary_hostname', 'another.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasErrors(['form.database_name']);

    expect(Site::query()->where('name', 'Another App')->exists())->toBeFalse();
});

test('opting out creates the site with no database', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
    ]);

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.name', 'No Db App')
        ->set('form.create_database', false)
        ->set('form.primary_hostname', 'nodb.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasNoErrors();

    $site = Site::query()->where('name', 'No Db App')->firstOrFail();

    expect(ServerDatabase::query()->where('site_id', $site->id)->exists())->toBeFalse();
    Queue::assertNotPushed(CreateSiteDatabaseJob::class);
});

test('a server with no database engines never offers or creates one', function () {
    Queue::fake();

    [$user, $server] = serverWithEngines([]);

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSet('form.create_database', false);

    expect($component->instance()->databaseCreationAvailable())->toBeFalse();

    $component
        ->set('form.name', 'Engineless')
        ->set('form.primary_hostname', 'engineless.example.test')
        ->set('form.type', 'php')
        ->set('form.php_version', '8.4')
        ->call('store')
        ->assertHasNoErrors();

    Queue::assertNotPushed(CreateSiteDatabaseJob::class);
});

test('static sites are not offered a database', function () {
    [$user, $server] = serverWithEngines([
        ['engine' => 'postgres', 'version' => '17', 'default' => true],
    ]);

    $component = Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.type', 'static');

    expect($component->instance()->databaseCreationAvailable())->toBeFalse();
});
