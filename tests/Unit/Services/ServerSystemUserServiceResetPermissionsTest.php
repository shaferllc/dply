<?php


namespace Tests\Unit\Services\ServerSystemUserServiceResetPermissionsTest;
use Mockery;

use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use App\Services\Servers\ServerSystemUserService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

test('reset site file permissions throws when repository path missing', function () {
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
});