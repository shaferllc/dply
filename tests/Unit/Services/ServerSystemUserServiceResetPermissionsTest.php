<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServerSystemUserServiceResetPermissionsTest extends TestCase
{
    // Required even though we use ->make() — Server::factory() and
    // Site::factory() reference User::factory() / Server::factory()
    // for foreign keys, and Laravel auto-creates (persists) those
    // nested factories even when the outer call is make(). Without
    // RefreshDatabase those Users + Servers leak into the suite and
    // break global counts in dply:fleet:* tests downstream.
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reset_site_file_permissions_throws_when_repository_path_missing(): void
    {
        $server = Server::factory()->ready()->make([
            'ssh_private_key' => 'not-empty-test-key',
        ]);
        $site = Site::factory()->make([
            'repository_path' => '',
            'document_root' => '',
        ]);
        $site->setRelation('server', $server);

        $runner = Mockery::mock(ServerSshConnectionRunner::class);
        $runner->shouldNotReceive('run');

        $service = new ServerSystemUserService($runner, new ServerSystemUserDeletionPolicy);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty');

        $service->resetSiteFilePermissions($site);
    }
}
