<?php

declare(strict_types=1);

namespace Tests\Feature\InstallDatabaseEngineOnServerTest;

use App\Actions\Servers\InstallDatabaseEngineOnServer;
use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('install runs apt steps then registers postgres', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $shell = new InstallDbRecordingShell;

    $action = $this->app->make(InstallDatabaseEngineOnServer::class);
    $result = $action->execute($server, 'postgres17', '17', isDefault: true, shellFactory: fn () => $shell);

    expect($result['ok'])->toBeTrue();
    expect($result['engine'])->toBe('postgres17');
    expect($shell->execCalls)->not->toBeEmpty();

    // Postgres install runs apt commands referencing postgresql-17.
    $combined = implode("\n", $shell->execCalls);
    $this->assertStringContainsString('postgresql-17', $combined);

    // Engine row was registered.
    $row = ServerDatabaseEngine::query()
        ->where('server_id', $server->id)
        ->where('engine', 'postgres17')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->version)->toBe('17');
    expect($row->is_default)->toBeTrue();
});
test('install runs apt steps for mysql 84', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $shell = new InstallDbRecordingShell;

    $action = $this->app->make(InstallDatabaseEngineOnServer::class);
    $result = $action->execute($server, 'mysql84', '8.4', shellFactory: fn () => $shell);

    expect($result['ok'])->toBeTrue();
    $combined = implode("\n", $shell->execCalls);
    $this->assertStringContainsString('mysql-server', $combined);
});
test('install returns unrecognized engine with ok false', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $shell = new InstallDbRecordingShell;

    $action = $this->app->make(InstallDatabaseEngineOnServer::class);
    $result = $action->execute($server, 'duckdb', null, shellFactory: fn () => $shell);

    expect($result['ok'])->toBeFalse();
    expect($shell->execCalls)->toBe([]);
    expect(ServerDatabaseEngine::query()->where('engine', 'duckdb')->first())->toBeNull();
});
test('install throws when server not ready', function () {
    $server = Server::factory()->create([
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => null,
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    $this->app->make(InstallDatabaseEngineOnServer::class)
        ->execute($server, 'postgres17', '17', shellFactory: fn () => new InstallDbRecordingShell);
});
test('install rejects blank engine', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $this->expectException(\InvalidArgumentException::class);

    $this->app->make(InstallDatabaseEngineOnServer::class)
        ->execute($server, '   ', null, shellFactory: fn () => new InstallDbRecordingShell);
});
test('add engine command with install flag falls back when engine unknown', function () {
    $server = Server::factory()->ready()->create([
        'name' => 'edge-1',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    // Need a mock shell — but the command uses the real action which
    // would try to SSH. Bind a fake action that no-ops and returns
    // ok=false to exercise the fallback path.
    $this->app->bind(InstallDatabaseEngineOnServer::class, function () {
        return new class extends InstallDatabaseEngineOnServer
        {
            public function __construct() {}

            public function execute(Server $server, string $engine, ?string $version = null, bool $isDefault = false, ?\Closure $shellFactory = null): array
            {
                return ['ok' => false, 'engine' => $engine, 'output' => ''];
            }
        };
    });

    $exit = Artisan::call('dply:server:add-engine', [
        'server' => 'edge-1',
        'engine' => 'duckdb',
        '--install' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No install steps known', $output);
    expect(ServerDatabaseEngine::query()->where('engine', 'duckdb')->first())->not->toBeNull();
});
class InstallDbRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
}
