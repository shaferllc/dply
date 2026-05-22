<?php


namespace Tests\Unit\ServerModelTest;
use App\Models\Server;
use Illuminate\Support\Facades\Schema;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('is ready returns true when status ready', function () {
    $server = Server::factory()->ready()->create();

    expect($server->isReady())->toBeTrue();
});

test('is ready returns false when status pending', function () {
    $server = Server::factory()->pending()->create();

    expect($server->isReady())->toBeFalse();
});

test('get ssh connection string formats correctly', function () {
    $server = Server::factory()->create([
        'ssh_user' => 'deploy',
        'ip_address' => '10.0.0.1',
    ]);

    expect($server->getSshConnectionString())->toBe('deploy@10.0.0.1');
});

test('get ssh connection string uses placeholder when no ip', function () {
    $server = Server::factory()->create([
        'ssh_user' => 'root',
        'ip_address' => null,
    ]);

    expect($server->getSshConnectionString())->toBe('root@0.0.0.0');
});

test('servers table has dual key columns', function () {
    expect(Schema::hasColumn('servers', 'ssh_operational_private_key'))->toBeTrue();
    expect(Schema::hasColumn('servers', 'ssh_recovery_private_key'))->toBeTrue();
});