<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Support\Sites\SiteDatabaseWorkspace;

function workspaceSite(?Server $server = null): Site
{
    $site = new Site;
    $site->setRelation('server', $server ?? new Server);
    $site->setRelation('serverDatabases', collect());
    $site->setRelation('bindings', collect());

    return $site;
}

test('on-box database bindings are not remote configurable', function () {
    $binding = new SiteBinding([
        'type' => 'database',
        'target_type' => 'server_database',
        'config' => ['placement' => 'on_box', 'host' => '127.0.0.1'],
        'injected_env' => ['DB_HOST' => '127.0.0.1'],
    ]);

    expect($binding->isRemoteConfigurableDatabase())->toBeFalse();
});

test('managed cloud database bindings are remote configurable', function () {
    $binding = new SiteBinding([
        'type' => 'database',
        'target_type' => 'cloud_database',
        'config' => ['placement' => 'managed', 'managed' => true, 'engine' => 'postgres'],
    ]);

    expect($binding->isRemoteConfigurableDatabase())->toBeTrue();
});

test('dedicated database vm bindings are remote configurable', function () {
    $binding = new SiteBinding([
        'type' => 'database',
        'target_type' => 'server_database',
        'config' => ['placement' => 'dedicated_vm'],
    ]);

    expect($binding->isRemoteConfigurableDatabase())->toBeTrue();
});

test('external host bindings are remote configurable', function () {
    $binding = new SiteBinding([
        'type' => 'database',
        'target_type' => null,
        'config' => ['host' => 'db.example.com'],
        'injected_env' => [],
    ]);

    expect($binding->isRemoteConfigurableDatabase())->toBeTrue();
});

test('redis bindings are never treated as the database tab', function () {
    $binding = new SiteBinding([
        'type' => 'redis',
        'target_type' => 'cloud_database',
        'config' => ['placement' => 'managed', 'managed' => true],
    ]);

    expect($binding->isRemoteConfigurableDatabase())->toBeFalse();
});

test('workspace hides the tab without engines databases or remote bindings', function () {
    $server = new Server;
    $server->setRelation('databaseEngines', collect());
    $site = workspaceSite($server);

    expect(SiteDatabaseWorkspace::shouldShowTab($site, $server))->toBeFalse();
});

test('workspace shows the tab for a running on-box engine', function () {
    $server = new Server;
    $engine = new ServerDatabaseEngine;
    $engine->status = ServerDatabaseEngine::STATUS_RUNNING;
    $server->setRelation('databaseEngines', collect([$engine]));
    $site = workspaceSite($server);

    expect(SiteDatabaseWorkspace::shouldShowTab($site, $server))->toBeTrue();
});

test('workspace summarizes a hosted binding without querying a cluster', function () {
    $binding = new SiteBinding([
        'type' => 'database',
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'config' => ['placement' => 'managed', 'managed' => true, 'engine' => 'postgres'],
        'injected_env' => ['DB_HOST' => 'db.example.com'],
    ]);
    $binding->id = 'binding-1';

    $site = workspaceSite();
    $site->setRelation('bindings', collect([$binding]));

    expect(SiteDatabaseWorkspace::remoteConfigurableSummaries($site))->toBe([[
        'id' => 'binding-1',
        'name' => 'primary',
        'engine' => 'Postgres',
        'placement' => 'Managed cluster',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'host' => 'db.example.com',
    ]]);
});
