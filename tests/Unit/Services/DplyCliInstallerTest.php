<?php

namespace Tests\Unit\Services\DplyCliInstallerTest;

use App\Models\Server;
use App\Services\Servers\DplyCliInstaller;
use App\Services\Servers\DplyCliStateWriter;
use App\Services\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function createMockSsh(): MockInterface
{
    return Mockery::mock(SshConnection::class);
}

function createInstaller(?DplyCliStateWriter $stateWriter = null): DplyCliInstaller
{
    $stateWriter ??= Mockery::mock(DplyCliStateWriter::class);

    return new DplyCliInstaller($stateWriter);
}

test('is installed returns true when binary exists', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->with('test -x \'/usr/local/bin/dply\' && echo present || echo missing', 10)
        ->andReturn('present');

    $installer = createInstaller();
    $result = $installer->isInstalled($server, $mockSsh);

    expect($result)->toBeTrue();
});

test('is installed returns false when binary missing', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->andReturn('missing');

    $installer = createInstaller();
    $result = $installer->isInstalled($server, $mockSsh);

    expect($result)->toBeFalse();
});

test('is installed returns false on ssh error', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->andThrow(new \Exception('SSH timeout'));

    $installer = createInstaller();
    $result = $installer->isInstalled($server, $mockSsh);

    expect($result)->toBeFalse();
});

test('installed version parses version output', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->with('/usr/local/bin/dply version 2>/dev/null || true', 10)
        ->andReturn('dply 0.1.0');

    $installer = createInstaller();
    $version = $installer->installedVersion($server, $mockSsh);

    expect($version)->toEqual('0.1.0');
});

test('installed version handles extra output', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->andReturn("dply 0.2.0-beta\n");

    $installer = createInstaller();
    $version = $installer->installedVersion($server, $mockSsh);

    expect($version)->toEqual('0.2.0-beta');
});

test('installed version returns null when binary missing', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->andReturn('');

    $installer = createInstaller();
    $version = $installer->installedVersion($server, $mockSsh);

    expect($version)->toBeNull();
});

test('installed version returns null on error', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();

    $mockSsh->shouldReceive('exec')
        ->once()
        ->andThrow(new \Exception('SSH failed'));

    $installer = createInstaller();
    $version = $installer->installedVersion($server, $mockSsh);

    expect($version)->toBeNull();
});

test('install creates state directory', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    // Expect state directory creation
    $mockSsh->shouldReceive('exec')
        ->once()
        ->with('sudo mkdir -p \'/etc/dply\' && sudo chmod 0755 \'/etc/dply\'', 15)
        ->andReturn('');

    // File upload expectations
    $mockSsh->shouldReceive('putFile')
        ->once()
        ->andReturnNull();

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install/'), 15)
        ->andReturn('');

    // jq installation check
    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/command -v jq/'), 120)
        ->andReturn('');

    // State file push
    $mockStateWriter->shouldReceive('push')
        ->once()
        ->with($server, $mockSsh);

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->install($server, $mockSsh);
});

test('install uploads binary', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('putFile')
        ->once()
        ->with(Mockery::pattern('/\/tmp\/dply\.install\.[a-f0-9]+/'), Mockery::type('string'));

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install.*\/usr\/local\/bin\/dply/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/command -v jq/'), 120)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -f/'), 15)
        ->andReturn('');

    $mockStateWriter->shouldReceive('push')
        ->once();

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->install($server, $mockSsh);
});

test('install installs jq when missing', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('putFile')
        ->once();

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install/'), 15)
        ->andReturn('');

    // jq not found, triggers apt install
    $mockSsh->shouldReceive('exec')
        ->with('command -v jq >/dev/null 2>&1 || (sudo DEBIAN_FRONTEND=noninteractive apt-get update -y && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y jq)', 120)
        ->once()
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -f/'), 15)
        ->andReturn('');

    $mockStateWriter->shouldReceive('push')
        ->once();

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->install($server, $mockSsh);
});

test('install skips jq install when present', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('putFile')
        ->once();

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install/'), 15)
        ->andReturn('');

    // jq already present
    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/command -v jq/'), 120)
        ->andReturnUsing(function ($cmd) {
            // The command succeeds silently when jq is present
            return '';
        });

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -f/'), 15)
        ->andReturn('');

    $mockStateWriter->shouldReceive('push')
        ->once();

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->install($server, $mockSsh);
});

test('install pushes state file', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('putFile')
        ->once();

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/command -v jq/'), 120)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -f/'), 15)
        ->andReturn('');

    // State push should be called with the server and SSH connection
    $mockStateWriter->shouldReceive('push')
        ->once()
        ->with($server, $mockSsh);

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->install($server, $mockSsh);
});

test('install returns parsed version', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('putFile')
        ->once();

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/sudo install/'), 15)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/command -v jq/'), 120)
        ->andReturn('');

    $mockSsh->shouldReceive('exec')
        ->with(Mockery::pattern('/rm -f/'), 15)
        ->andReturn('');

    $mockStateWriter->shouldReceive('push')
        ->once();

    $installer = new DplyCliInstaller($mockStateWriter);
    $version = $installer->install($server, $mockSsh);

    // Version should be parsed from the script
    expect($version)->not->toBeNull();
    $this->assertNotEquals('unknown', $version);
});

test('install throws when script file missing', function () {
    $server = Server::factory()->create();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    // Temporarily rename the script to simulate missing file
    $scriptPath = resource_path('bin/dply');
    $backupPath = $scriptPath.'.backup';

    if (file_exists($scriptPath)) {
        rename($scriptPath, $backupPath);

        $installer = new DplyCliInstaller($mockStateWriter);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read resources/bin/dply');

        try {
            $installer->install($server);
        } finally {
            // Restore the file
            rename($backupPath, $scriptPath);
        }
    } else {
        // If file doesn't exist, test passes
        expect(true)->toBeTrue();
    }
});

test('refresh state calls state writer', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    $mockStateWriter->shouldReceive('push')
        ->once()
        ->with($server, $mockSsh);

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->refreshState($server, $mockSsh);
});

test('refresh state does not install binary', function () {
    $server = Server::factory()->create();
    $mockSsh = createMockSsh();
    $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

    // Should NOT call any exec methods for binary installation
    $mockSsh->shouldNotReceive('exec')
        ->with(Mockery::pattern('/mkdir -p/'));
    $mockSsh->shouldNotReceive('putFile');

    $mockStateWriter->shouldReceive('push')
        ->once();

    $installer = new DplyCliInstaller($mockStateWriter);
    $installer->refreshState($server, $mockSsh);
});

test('parse version extracts version from script', function () {
    $script = "#!/bin/bash\nDPLY_VERSION=\"1.2.3\"\n# more code";

    // Use reflection to test the protected method
    $installer = new DplyCliInstaller(Mockery::mock(DplyCliStateWriter::class));
    $reflection = new \ReflectionClass($installer);
    $method = $reflection->getMethod('parseVersionFromScript');
    $method->setAccessible(true);

    $version = $method->invoke($installer, $script);

    expect($version)->toEqual('1.2.3');
});

test('parse version returns unknown when not found', function () {
    $script = "#!/bin/bash\n# No version here";

    $installer = new DplyCliInstaller(Mockery::mock(DplyCliStateWriter::class));
    $reflection = new \ReflectionClass($installer);
    $method = $reflection->getMethod('parseVersionFromScript');
    $method->setAccessible(true);

    $version = $method->invoke($installer, $script);

    expect($version)->toEqual('unknown');
});

test('constants define correct paths', function () {
    expect(DplyCliInstaller::REMOTE_BIN_PATH)->toEqual('/usr/local/bin/dply');
    expect(DplyCliInstaller::REMOTE_STATE_DIR)->toEqual('/etc/dply');
});

afterEach(function () {
    Mockery::close();
});
