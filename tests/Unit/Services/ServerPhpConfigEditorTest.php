<?php


namespace Tests\Unit\Services\ServerPhpConfigEditorTest;
use Mockery;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpConfigValidationException;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServerWithMeta(array $meta = []): Server
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'owner']);

    return Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => array_merge([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ], $meta),
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
}

it('resolves the expected paths and labels for each supported target', function () {
    $server = makeServerWithMeta();
    $editor = app(ServerPhpConfigEditor::class);

    $cliTarget = $editor->resolveEditableTarget($server, '8.3', 'cli_ini');
    $fpmTarget = $editor->resolveEditableTarget($server, '8.3', 'fpm_ini');
    $poolTarget = $editor->resolveEditableTarget($server, '8.3', 'pool_config');

    expect($cliTarget['label'])->toBe('CLI ini');
    expect($cliTarget['path'])->toBe('/etc/php/8.3/cli/php.ini');
    expect($fpmTarget['label'])->toBe('FPM ini');
    expect($fpmTarget['path'])->toBe('/etc/php/8.3/fpm/php.ini');
    expect($poolTarget['label'])->toBe('Pool config');
    expect($poolTarget['path'])->toBe('/etc/php/8.3/fpm/pool.d/www.conf');
});

it('reads the current content for a resolved target', function () {
    $server = makeServerWithMeta();

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(\App\Services\ConfigRevisions\ConfigRevisionRecorder::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $editor->shouldReceive('readRemoteTarget')
        ->once()
        ->withArgs(fn (Server $refreshedServer, array $target) => $refreshedServer->is($server) && $target['path'] === '/etc/php/8.3/cli/php.ini')
        ->andReturn("memory_limit=512M\n");

    $result = $editor->openTarget($server, '8.3', 'cli_ini');

    expect($result['label'])->toBe('CLI ini');
    expect($result['path'])->toBe('/etc/php/8.3/cli/php.ini');
    expect($result['content'])->toBe("memory_limit=512M\n");
});

it('rejects validation failures before the live file is replaced', function () {
    $server = makeServerWithMeta();

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(\App\Services\ConfigRevisions\ConfigRevisionRecorder::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $editor->shouldReceive('verifyProposedContent')
        ->once()
        ->andThrow(new ServerPhpConfigValidationException(
            'CLI ini validation failed. The live file was not replaced.',
            'PHP:  syntax error, unexpected "=" on line 2'
        ));
    $editor->shouldNotReceive('replaceRemoteTarget');

    $this->expectException(ServerPhpConfigValidationException::class);
    $this->expectExceptionMessage('CLI ini validation failed. The live file was not replaced.');

    $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit==512M\n");
});

it('fails clearly for unsupported target types', function () {
    $server = makeServerWithMeta();
    $editor = app(ServerPhpConfigEditor::class);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unknown PHP config target.');

    $editor->resolveEditableTarget($server, '8.3', 'apache_ini');
});

it('fails clearly when the expected target is missing on the server', function () {
    $server = makeServerWithMeta();

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(\App\Services\ConfigRevisions\ConfigRevisionRecorder::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $editor->shouldReceive('readRemoteTarget')
        ->once()
        ->andThrow(new \RuntimeException('Pool config is not available for PHP 8.3 on this server.'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Pool config is not available for PHP 8.3 on this server.');

    $editor->openTarget($server, '8.3', 'pool_config');
});

it('returns reload guidance after a verified write succeeds', function () {
    $server = makeServerWithMeta();

    $editor = Mockery::mock(ServerPhpConfigEditor::class, [app(\App\Services\ConfigRevisions\ConfigRevisionRecorder::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $editor->shouldReceive('verifyProposedContent')
        ->once()
        ->andReturn([
            'output' => 'configuration file syntax is ok',
        ]);
    $editor->shouldReceive('replaceRemoteTarget')
        ->once();
    $editor->shouldReceive('reloadRuntimeIfNeeded')
        ->once()
        ->andReturn('FPM ini saved and PHP-FPM 8.3 reloaded.');

    $result = $editor->saveTarget($server, '8.3', 'fpm_ini', "memory_limit=512M\n");

    expect($result['message'])->toBe('FPM ini saved and PHP-FPM 8.3 reloaded.');
    $this->assertStringContainsString('reloaded automatically', $result['reload_guidance']);
    expect($result['verification_output'])->toBe('configuration file syntax is ok');
    expect($result['output'])->toBe("configuration file syntax is ok\n\nFPM ini saved and PHP-FPM 8.3 reloaded.");
});

it('rejects config edits while another server level php mutation is running', function () {
    $server = makeServerWithMeta();
    $editor = app(ServerPhpConfigEditor::class);
    $lock = Cache::lock('server-php-package-action:'.$server->id, 150);

    expect($lock->get())->toBeTrue();

    try {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another PHP server mutation is already running for this server.');

        $editor->saveTarget($server, '8.3', 'cli_ini', "memory_limit=512M\n");
    } finally {
        $lock->release();
    }
});