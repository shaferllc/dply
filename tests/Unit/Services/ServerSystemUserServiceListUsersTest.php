<?php


namespace Tests\Unit\Services\ServerSystemUserServiceListUsersTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

test('list rows flag orphan and protected accounts', function () {
    config(['server_provision.deploy_ssh_user' => 'dply']);

    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->for($org)->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => 'fake-key',
    ]);

    Site::factory()->for($server)->for($org)->for($user)->create([
        'php_fpm_user' => 'busyuser',
        'name' => 'shop.example',
    ]);

    $lister = Mockery::mock(ServerPasswdUserLister::class);
    $lister->shouldReceive('listPasswdDetails')
        ->with($server)
        ->andReturn([
            ['username' => 'busyuser', 'uid' => 1003, 'home' => '/home/busyuser', 'shell' => '/bin/bash', 'groups' => ['busyuser', 'www-data']],
            ['username' => 'dply',     'uid' => 1000, 'home' => '/home/dply',     'shell' => '/bin/bash', 'groups' => ['dply', 'sudo']],
            ['username' => 'orphan1',  'uid' => 1004, 'home' => '/home/orphan1',  'shell' => '/bin/bash', 'groups' => ['orphan1']],
            ['username' => 'orphan2',  'uid' => 1005, 'home' => '/home/orphan2',  'shell' => '/bin/sh',   'groups' => ['orphan2']],
            ['username' => 'root',     'uid' => 0,    'home' => '/root',          'shell' => '/bin/bash', 'groups' => ['root']],
        ]);

    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $service = new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy);

    $rows = $service->listPasswdUsersWithSiteCounts($server, $lister);
    $byName = collect($rows)->keyBy('username');

    expect($byName['root']['is_protected'])->toBeTrue();
    expect($byName['root']['is_orphan'])->toBeFalse();

    expect($byName['dply']['is_protected'])->toBeTrue();
    expect($byName['dply']['is_orphan'])->toBeFalse();
    expect($byName['dply']['uid'])->toBe(1000);
    expect($byName['dply']['groups'])->toContain('sudo');

    expect($byName['busyuser']['is_protected'])->toBeFalse();
    expect($byName['busyuser']['is_orphan'])->toBeFalse();
    expect($byName['busyuser']['site_count'])->toBe(1);
    expect($byName['busyuser']['sites'][0]['name'])->toBe('shop.example');

    expect($byName['orphan1']['is_protected'])->toBeFalse();
    expect($byName['orphan1']['is_orphan'])->toBeTrue();
    expect($byName['orphan1']['site_count'])->toBe(0);
    expect($byName['orphan1']['home'])->toBe('/home/orphan1');
    expect($byName['orphan1']['sites'])->toBe([]);

    expect($byName['orphan2']['is_protected'])->toBeFalse();
    expect($byName['orphan2']['is_orphan'])->toBeTrue();
});

test('sync persists snapshot and removes vanished users', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => 'fake-key',
    ]);

    ServerSystemUser::create([
        'server_id' => $server->id,
        'username' => 'vanished',
        'uid' => 1099,
        'home' => '/home/vanished',
        'shell' => '/bin/bash',
        'groups' => ['vanished'],
        'last_seen_at' => now()->subDay(),
    ]);

    $lister = Mockery::mock(ServerPasswdUserLister::class);
    $lister->shouldReceive('listPasswdDetails')
        ->with(Mockery::on(fn (Server $s): bool => $s->id === $server->id))
        ->andReturn([
            ['username' => 'kept',    'uid' => 1001, 'home' => '/home/kept',    'shell' => '/bin/bash', 'groups' => ['kept', 'sudo']],
            ['username' => 'arrived', 'uid' => 1002, 'home' => '/home/arrived', 'shell' => '/bin/sh',   'groups' => ['arrived']],
        ]);

    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $service = new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy);

    $service->listPasswdUsersWithSiteCounts($server, $lister);

    $persisted = ServerSystemUser::query()->where('server_id', $server->id)->pluck('username')->all();
    sort($persisted);
    expect($persisted)->toBe(['arrived', 'kept']);

    $arrived = ServerSystemUser::where('server_id', $server->id)->where('username', 'arrived')->firstOrFail();
    expect($arrived->uid)->toBe(1002);
    expect($arrived->home)->toBe('/home/arrived');
    expect($arrived->groups)->toBe(['arrived']);
    expect($arrived->last_seen_at)->not->toBeNull();
});

test('stored system users with metadata reads from db without ssh', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->for($org)->ready()->create(['ssh_user' => 'dply']);

    Site::factory()->for($server)->for($org)->for($user)->create([
        'php_fpm_user' => 'apprunner',
        'name' => 'shop.example',
    ]);

    ServerSystemUser::create([
        'server_id' => $server->id,
        'username' => 'apprunner',
        'uid' => 1010,
        'home' => '/home/apprunner',
        'shell' => '/bin/bash',
        'groups' => ['apprunner', 'www-data'],
        'last_seen_at' => now(),
    ]);
    ServerSystemUser::create([
        'server_id' => $server->id,
        'username' => 'leftover',
        'uid' => 1011,
        'home' => '/home/leftover',
        'shell' => '/bin/bash',
        'groups' => ['leftover'],
        'last_seen_at' => now(),
    ]);

    $runner = Mockery::mock(ServerSshConnectionRunner::class);
    $runner->shouldNotReceive('run');
    $service = new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy);

    $rows = $service->storedSystemUsersWithMetadata($server);
    $byName = collect($rows)->keyBy('username');

    expect(collect($rows)->pluck('username')->all())->toBe(['apprunner', 'leftover']);
    expect($byName['apprunner']['site_count'])->toBe(1);
    expect($byName['apprunner']['is_orphan'])->toBeFalse();
    expect($byName['leftover']['is_orphan'])->toBeTrue();
    expect($byName['apprunner']['sites'][0]['name'])->toBe('shop.example');
    expect($byName['apprunner']['groups'])->toBe(['apprunner', 'www-data']);
});