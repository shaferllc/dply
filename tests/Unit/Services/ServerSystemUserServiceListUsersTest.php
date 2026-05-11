<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServerSystemUserServiceListUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_rows_flag_orphan_and_protected_accounts(): void
    {
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

        $this->assertTrue($byName['root']['is_protected']);
        $this->assertFalse($byName['root']['is_orphan']);

        $this->assertTrue($byName['dply']['is_protected']);
        $this->assertFalse($byName['dply']['is_orphan']);
        $this->assertSame(1000, $byName['dply']['uid']);
        $this->assertContains('sudo', $byName['dply']['groups']);

        $this->assertFalse($byName['busyuser']['is_protected']);
        $this->assertFalse($byName['busyuser']['is_orphan']);
        $this->assertSame(1, $byName['busyuser']['site_count']);
        $this->assertSame('shop.example', $byName['busyuser']['sites'][0]['name']);

        $this->assertFalse($byName['orphan1']['is_protected']);
        $this->assertTrue($byName['orphan1']['is_orphan']);
        $this->assertSame(0, $byName['orphan1']['site_count']);
        $this->assertSame('/home/orphan1', $byName['orphan1']['home']);
        $this->assertSame([], $byName['orphan1']['sites']);

        $this->assertFalse($byName['orphan2']['is_protected']);
        $this->assertTrue($byName['orphan2']['is_orphan']);
    }

    public function test_sync_persists_snapshot_and_removes_vanished_users(): void
    {
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
        $this->assertSame(['arrived', 'kept'], $persisted);

        $arrived = ServerSystemUser::where('server_id', $server->id)->where('username', 'arrived')->firstOrFail();
        $this->assertSame(1002, $arrived->uid);
        $this->assertSame('/home/arrived', $arrived->home);
        $this->assertSame(['arrived'], $arrived->groups);
        $this->assertNotNull($arrived->last_seen_at);
    }

    public function test_stored_system_users_with_metadata_reads_from_db_without_ssh(): void
    {
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

        $this->assertSame(['apprunner', 'leftover'], collect($rows)->pluck('username')->all());
        $this->assertSame(1, $byName['apprunner']['site_count']);
        $this->assertFalse($byName['apprunner']['is_orphan']);
        $this->assertTrue($byName['leftover']['is_orphan']);
        $this->assertSame('shop.example', $byName['apprunner']['sites'][0]['name']);
        $this->assertSame(['apprunner', 'www-data'], $byName['apprunner']['groups']);
    }
}
