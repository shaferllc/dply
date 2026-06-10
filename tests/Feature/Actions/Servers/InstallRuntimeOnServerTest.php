<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\Servers\InstallRuntimeOnServerTest;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Models\Server;
use App\Services\Servers\MiseInstallScriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('installs node via mise use global and records default', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        'meta' => [],
    ]);
    $shell = new InstallRuntimeRecordingShell;

    $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
        ->execute($server, 'node', '22.7.0', fn () => $shell);

    expect($result['installed'])->toBeTrue();
    expect($result['runtime'])->toBe('node');
    expect($result['version'])->toBe('22.7.0');

    $hadInstallCall = collect($shell->execCalls)
        ->contains(fn ($c) => str_contains($c, 'mise use --global node@22.7.0'));
    expect($hadInstallCall)->toBeTrue();

    $server->refresh();
    expect($server->meta['runtime_defaults'])->toBe(['node' => '22.7.0']);
});
test('skips php runtime silently', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        'meta' => [],
    ]);
    $shell = new InstallRuntimeRecordingShell;

    $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
        ->execute($server, 'php', '8.4', fn () => $shell);

    expect($result['installed'])->toBeFalse();
    expect($shell->execCalls)->toBe([]);

    $server->refresh();
    expect($server->meta['runtime_defaults'] ?? null)->toBeNull();
});
test('skips unknown runtime silently', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        'meta' => [],
    ]);
    $shell = new InstallRuntimeRecordingShell;

    $result = (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
        ->execute($server, 'erlang', '27', fn () => $shell);

    expect($result['installed'])->toBeFalse();
});
test('throws when server not ready', function () {
    $server = Server::factory()->create([
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => null,
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
        ->execute($server, 'node', '22.7.0', fn () => new InstallRuntimeRecordingShell);
});
test('merges runtime default with existing entries', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        'meta' => [
            'runtime_defaults' => ['node' => '22'],
        ],
    ]);
    $shell = new InstallRuntimeRecordingShell;

    (new InstallRuntimeOnServer(new MiseInstallScriptBuilder))
        ->execute($server, 'python', '3.12', fn () => $shell);

    $server->refresh();
    expect($server->meta['runtime_defaults'])->toBe(['node' => '22', 'python' => '3.12']);
});
