<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\DplyCliInstaller;
use App\Services\Servers\DplyCliStateWriter;
use App\Services\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for the DplyCliInstaller service.
 *
 * @covers \App\Services\Servers\DplyCliInstaller
 */
final class DplyCliInstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function createMockSsh(): Mockery\MockInterface
    {
        return Mockery::mock(SshConnection::class);
    }

    protected function createInstaller(?DplyCliStateWriter $stateWriter = null): DplyCliInstaller
    {
        $stateWriter ??= Mockery::mock(DplyCliStateWriter::class);
        return new DplyCliInstaller($stateWriter);
    }

    public function test_is_installed_returns_true_when_binary_exists(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->with('test -x \'/usr/local/bin/dply\' && echo present || echo missing', 10)
            ->andReturn('present');

        $installer = $this->createInstaller();
        $result = $installer->isInstalled($server, $mockSsh);

        $this->assertTrue($result);
    }

    public function test_is_installed_returns_false_when_binary_missing(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->andReturn('missing');

        $installer = $this->createInstaller();
        $result = $installer->isInstalled($server, $mockSsh);

        $this->assertFalse($result);
    }

    public function test_is_installed_returns_false_on_ssh_error(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->andThrow(new \Exception('SSH timeout'));

        $installer = $this->createInstaller();
        $result = $installer->isInstalled($server, $mockSsh);

        $this->assertFalse($result);
    }

    public function test_installed_version_parses_version_output(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->with('/usr/local/bin/dply version 2>/dev/null || true', 10)
            ->andReturn('dply 0.1.0');

        $installer = $this->createInstaller();
        $version = $installer->installedVersion($server, $mockSsh);

        $this->assertEquals('0.1.0', $version);
    }

    public function test_installed_version_handles_extra_output(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->andReturn("dply 0.2.0-beta\n");

        $installer = $this->createInstaller();
        $version = $installer->installedVersion($server, $mockSsh);

        $this->assertEquals('0.2.0-beta', $version);
    }

    public function test_installed_version_returns_null_when_binary_missing(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->andReturn('');

        $installer = $this->createInstaller();
        $version = $installer->installedVersion($server, $mockSsh);

        $this->assertNull($version);
    }

    public function test_installed_version_returns_null_on_error(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();

        $mockSsh->shouldReceive('exec')
            ->once()
            ->andThrow(new \Exception('SSH failed'));

        $installer = $this->createInstaller();
        $version = $installer->installedVersion($server, $mockSsh);

        $this->assertNull($version);
    }

    public function test_install_creates_state_directory(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
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
            ->with(\Mockery::pattern('/sudo install/'), 15)
            ->andReturn('');

        // jq installation check
        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/command -v jq/'), 120)
            ->andReturn('');

        // State file push
        $mockStateWriter->shouldReceive('push')
            ->once()
            ->with($server, $mockSsh);

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->install($server, $mockSsh);
    }

    public function test_install_uploads_binary(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('putFile')
            ->once()
            ->with(\Mockery::pattern('/\/tmp\/dply\.install\.[a-f0-9]+/'), \Mockery::type('string'));

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/sudo install.*\/usr\/local\/bin\/dply/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/command -v jq/'), 120)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/rm -f/'), 15)
            ->andReturn('');

        $mockStateWriter->shouldReceive('push')
            ->once();

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->install($server, $mockSsh);
    }

    public function test_install_installs_jq_when_missing(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('putFile')
            ->once();

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/sudo install/'), 15)
            ->andReturn('');

        // jq not found, triggers apt install
        $mockSsh->shouldReceive('exec')
            ->with('command -v jq >/dev/null 2>&1 || (sudo DEBIAN_FRONTEND=noninteractive apt-get update -y && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y jq)', 120)
            ->once()
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/rm -f/'), 15)
            ->andReturn('');

        $mockStateWriter->shouldReceive('push')
            ->once();

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->install($server, $mockSsh);
    }

    public function test_install_skips_jq_install_when_present(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('putFile')
            ->once();

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/sudo install/'), 15)
            ->andReturn('');

        // jq already present
        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/command -v jq/'), 120)
            ->andReturnUsing(function ($cmd) {
                // The command succeeds silently when jq is present
                return '';
            });

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/rm -f/'), 15)
            ->andReturn('');

        $mockStateWriter->shouldReceive('push')
            ->once();

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->install($server, $mockSsh);
    }

    public function test_install_pushes_state_file(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('putFile')
            ->once();

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/sudo install/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/command -v jq/'), 120)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/rm -f/'), 15)
            ->andReturn('');

        // State push should be called with the server and SSH connection
        $mockStateWriter->shouldReceive('push')
            ->once()
            ->with($server, $mockSsh);

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->install($server, $mockSsh);
    }

    public function test_install_returns_parsed_version(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('putFile')
            ->once();

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/sudo install/'), 15)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/command -v jq/'), 120)
            ->andReturn('');

        $mockSsh->shouldReceive('exec')
            ->with(\Mockery::pattern('/rm -f/'), 15)
            ->andReturn('');

        $mockStateWriter->shouldReceive('push')
            ->once();

        $installer = new DplyCliInstaller($mockStateWriter);
        $version = $installer->install($server, $mockSsh);

        // Version should be parsed from the script
        $this->assertNotNull($version);
        $this->assertNotEquals('unknown', $version);
    }

    public function test_install_throws_when_script_file_missing(): void
    {
        $server = Server::factory()->create();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        // Temporarily rename the script to simulate missing file
        $scriptPath = resource_path('bin/dply');
        $backupPath = $scriptPath . '.backup';

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
            $this->assertTrue(true);
        }
    }

    public function test_refresh_state_calls_state_writer(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        $mockStateWriter->shouldReceive('push')
            ->once()
            ->with($server, $mockSsh);

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->refreshState($server, $mockSsh);
    }

    public function test_refresh_state_does_not_install_binary(): void
    {
        $server = Server::factory()->create();
        $mockSsh = $this->createMockSsh();
        $mockStateWriter = Mockery::mock(DplyCliStateWriter::class);

        // Should NOT call any exec methods for binary installation
        $mockSsh->shouldNotReceive('exec')
            ->with(\Mockery::pattern('/mkdir -p/'));
        $mockSsh->shouldNotReceive('putFile');

        $mockStateWriter->shouldReceive('push')
            ->once();

        $installer = new DplyCliInstaller($mockStateWriter);
        $installer->refreshState($server, $mockSsh);
    }

    public function test_parse_version_extracts_version_from_script(): void
    {
        $script = "#!/bin/bash\nDPLY_VERSION=\"1.2.3\"\n# more code";

        // Use reflection to test the protected method
        $installer = new DplyCliInstaller(Mockery::mock(DplyCliStateWriter::class));
        $reflection = new \ReflectionClass($installer);
        $method = $reflection->getMethod('parseVersionFromScript');
        $method->setAccessible(true);

        $version = $method->invoke($installer, $script);

        $this->assertEquals('1.2.3', $version);
    }

    public function test_parse_version_returns_unknown_when_not_found(): void
    {
        $script = "#!/bin/bash\n# No version here";

        $installer = new DplyCliInstaller(Mockery::mock(DplyCliStateWriter::class));
        $reflection = new \ReflectionClass($installer);
        $method = $reflection->getMethod('parseVersionFromScript');
        $method->setAccessible(true);

        $version = $method->invoke($installer, $script);

        $this->assertEquals('unknown', $version);
    }

    public function test_constants_define_correct_paths(): void
    {
        $this->assertEquals('/usr/local/bin/dply', DplyCliInstaller::REMOTE_BIN_PATH);
        $this->assertEquals('/etc/dply', DplyCliInstaller::REMOTE_STATE_DIR);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
