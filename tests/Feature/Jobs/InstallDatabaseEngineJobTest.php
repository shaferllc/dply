<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\InstallDatabaseEngineJobTest;

use App\Jobs\InstallDatabaseEngineJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\DatabaseEngineAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});
/**
 * @return array{0: User, 1: Server, 2: ServerDatabaseEngine}
 */
function makeSetup(string $engine = 'mysql'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $row = ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => $engine,
        'is_default' => true,
        'status' => ServerDatabaseEngine::STATUS_PENDING,
        'port' => ServerDatabaseEngine::defaultPortFor($engine),
    ]);

    return [$user, $server, $row];
}
test('install runs apt and marks running', function () {
    [$user, $server, $row] = makeSetup('mysql');

    $this->mock(ServerResourcePreflight::class, function ($mock): void {
        $mock->shouldReceive('check')->once()->andReturn([
            'ok' => true, 'reason' => null,
            'available_ram_mb' => 4096, 'available_disk_mb' => 20480,
            'required_ram_mb' => 512, 'required_disk_mb' => 1024,
        ]);
    });

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')->once()
            ->andReturn(new ProcessOutput("install ok\nmysql 8.0.39\n", 0, false));
    });

    $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forget')->once();
    });

    (new InstallDatabaseEngineJob($row->id))
        ->handle(
            app(ExecuteRemoteTaskOnServer::class),
            app(ServerDatabaseHostCapabilities::class),
            app(DatabaseEngineAuditLogger::class),
            app(ServerResourcePreflight::class),
        );

    $row->refresh();
    expect($row->status)->toBe(ServerDatabaseEngine::STATUS_RUNNING);
    expect($row->version)->toBe('mysql 8.0.39');
    $this->assertDatabaseHas('server_database_engine_audit_events', [
        'server_id' => $server->id,
        'event' => ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALLED,
    ]);
});
test('install blocks on resource preflight', function () {
    [$user, $server, $row] = makeSetup('mysql');

    $this->mock(ServerResourcePreflight::class, function ($mock): void {
        $mock->shouldReceive('check')->once()->andReturn([
            'ok' => false,
            'reason' => 'Insufficient memory: requires 512 MB, have 128 MB available.',
            'available_ram_mb' => 128, 'available_disk_mb' => 8192,
            'required_ram_mb' => 512, 'required_disk_mb' => 1024,
        ]);
    });

    // Apt must NOT run when preflight fails.
    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldNotReceive('runInlineBash');
    });

    $this->mock(ServerDatabaseHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forget')->zeroOrMoreTimes();
    });

    (new InstallDatabaseEngineJob($row->id))
        ->handle(
            app(ExecuteRemoteTaskOnServer::class),
            app(ServerDatabaseHostCapabilities::class),
            app(DatabaseEngineAuditLogger::class),
            app(ServerResourcePreflight::class),
        );

    $row->refresh();
    expect($row->status)->toBe(ServerDatabaseEngine::STATUS_FAILED);
    $this->assertStringContainsString('Insufficient memory', (string) $row->error_message);
    $this->assertDatabaseHas('server_database_engine_audit_events', [
        'server_id' => $server->id,
        'event' => ServerDatabaseEngineAuditEvent::EVENT_ENGINE_INSTALL_FAILED,
    ]);
});
