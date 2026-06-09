<?php

declare(strict_types=1);

namespace Tests\Feature\ServerResourcePreflightTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});
function makeServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}
test('check passes when resources meet thresholds', function () {
    $server = makeServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->once()
            ->andReturn(new ProcessOutput("ram_mb=2048\ndisk_mb=8192\n", 0, false));
    });

    $result = app(ServerResourcePreflight::class)->check($server, [
        'min_ram_mb' => 512,
        'min_disk_mb' => 1024,
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['reason'])->toBeNull();
    expect($result['available_ram_mb'])->toBe(2048);
    expect($result['available_disk_mb'])->toBe(8192);
});
test('check fails with specific reason when ram below threshold', function () {
    $server = makeServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')->once()
            ->andReturn(new ProcessOutput("ram_mb=128\ndisk_mb=8192\n", 0, false));
    });

    $result = app(ServerResourcePreflight::class)->check($server, [
        'min_ram_mb' => 512,
        'min_disk_mb' => 1024,
    ]);

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('memory', strtolower((string) $result['reason']));
    $this->assertStringContainsString('512 MB', (string) $result['reason']);
    $this->assertStringContainsString('128 MB', (string) $result['reason']);
});
test('check fails when disk below threshold', function () {
    $server = makeServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')->once()
            ->andReturn(new ProcessOutput("ram_mb=2048\ndisk_mb=64\n", 0, false));
    });

    $result = app(ServerResourcePreflight::class)->check($server, [
        'min_ram_mb' => 512,
        'min_disk_mb' => 1024,
    ]);

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('disk', strtolower((string) $result['reason']));
});
test('check handles unexpected output', function () {
    $server = makeServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')->once()
            ->andReturn(new ProcessOutput("garbage\n", 0, false));
    });

    $result = app(ServerResourcePreflight::class)->check($server, [
        'min_ram_mb' => 0,
        'min_disk_mb' => 0,
    ]);

    expect($result['ok'])->toBeFalse();
    $this->assertStringContainsString('unexpected output', (string) $result['reason']);
});
test('requirements helpers read from config', function () {
    $cache = ServerResourcePreflight::requirementsForCacheEngine('memcached');
    expect($cache['min_ram_mb'])->toBe(64);
    expect($cache['min_disk_mb'])->toBe(64);

    $db = ServerResourcePreflight::requirementsForDatabaseEngine('mysql');
    expect($db['min_ram_mb'])->toBe(512);

    // Unknown engine falls back to the `_default` row.
    $unknown = ServerResourcePreflight::requirementsForDatabaseEngine('totally-not-real');
    expect($unknown['min_ram_mb'])->toBeGreaterThan(0);
});
