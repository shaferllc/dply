<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\ServerResourcePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerResourcePreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeServer(): Server
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

    public function test_check_passes_when_resources_meet_thresholds(): void
    {
        $server = $this->makeServer();

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->andReturn(new ProcessOutput("ram_mb=2048\ndisk_mb=8192\n", 0, false));
        });

        $result = app(ServerResourcePreflight::class)->check($server, [
            'min_ram_mb' => 512,
            'min_disk_mb' => 1024,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['reason']);
        $this->assertSame(2048, $result['available_ram_mb']);
        $this->assertSame(8192, $result['available_disk_mb']);
    }

    public function test_check_fails_with_specific_reason_when_ram_below_threshold(): void
    {
        $server = $this->makeServer();

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')->once()
                ->andReturn(new ProcessOutput("ram_mb=128\ndisk_mb=8192\n", 0, false));
        });

        $result = app(ServerResourcePreflight::class)->check($server, [
            'min_ram_mb' => 512,
            'min_disk_mb' => 1024,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('memory', strtolower((string) $result['reason']));
        $this->assertStringContainsString('512 MB', (string) $result['reason']);
        $this->assertStringContainsString('128 MB', (string) $result['reason']);
    }

    public function test_check_fails_when_disk_below_threshold(): void
    {
        $server = $this->makeServer();

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')->once()
                ->andReturn(new ProcessOutput("ram_mb=2048\ndisk_mb=64\n", 0, false));
        });

        $result = app(ServerResourcePreflight::class)->check($server, [
            'min_ram_mb' => 512,
            'min_disk_mb' => 1024,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('disk', strtolower((string) $result['reason']));
    }

    public function test_check_handles_unexpected_output(): void
    {
        $server = $this->makeServer();

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')->once()
                ->andReturn(new ProcessOutput("garbage\n", 0, false));
        });

        $result = app(ServerResourcePreflight::class)->check($server, [
            'min_ram_mb' => 0,
            'min_disk_mb' => 0,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unexpected output', (string) $result['reason']);
    }

    public function test_requirements_helpers_read_from_config(): void
    {
        $cache = ServerResourcePreflight::requirementsForCacheEngine('memcached');
        $this->assertSame(64, $cache['min_ram_mb']);
        $this->assertSame(64, $cache['min_disk_mb']);

        $db = ServerResourcePreflight::requirementsForDatabaseEngine('mysql');
        $this->assertSame(512, $db['min_ram_mb']);

        // Unknown engine falls back to the `_default` row.
        $unknown = ServerResourcePreflight::requirementsForDatabaseEngine('totally-not-real');
        $this->assertGreaterThan(0, $unknown['min_ram_mb']);
    }
}
