<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServicePort;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for the on-server bash that swaps an engine's listen port. The job/Livewire layer
 * already has its own feature tests; this exercises the script builder in isolation so the
 * engine-specific sed patterns and verification commands stay correct as we add engines.
 */
class CacheServicePortTest extends TestCase
{
    private CacheServicePort $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->port = new CacheServicePort(Mockery::mock(ExecuteRemoteTaskOnServer::class));
    }

    public function test_redis_default_instance_script_replaces_port_directive(): void
    {
        $row = $this->row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
        $script = $this->port->buildScript($row, '/etc/redis/redis.conf', 'redis-server', 6390);

        $this->assertStringContainsString("sed -i.tmp '/^[[:space:]]*port[[:space:]]/d' /etc/redis/redis.conf", $script);
        $this->assertStringContainsString("printf 'port %d\\n' 6390 >> /etc/redis/redis.conf", $script);
        $this->assertStringContainsString('systemctl restart redis-server', $script);
        $this->assertStringContainsString('redis-cli -p 6390 ping', $script);
    }

    public function test_valkey_named_instance_script_uses_templated_unit_and_cli(): void
    {
        $row = $this->row('valkey', 'sessions', 6380);
        $script = $this->port->buildScript($row, '/etc/valkey/valkey-sessions.conf', 'valkey-server@sessions', 6395);

        $this->assertStringContainsString('systemctl restart valkey-server@sessions', $script);
        $this->assertStringContainsString('valkey-cli -p 6395 ping', $script);
        $this->assertStringContainsString("printf 'port %d\\n' 6395 >> /etc/valkey/valkey-sessions.conf", $script);
    }

    public function test_keydb_uses_keydb_cli_for_verification(): void
    {
        $row = $this->row('keydb', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
        $script = $this->port->buildScript($row, '/etc/keydb/keydb.conf', 'keydb-server', 6391);

        $this->assertStringContainsString('keydb-cli -p 6391 ping', $script);
    }

    public function test_redis_with_auth_password_passes_it_to_verify_ping(): void
    {
        $row = $this->row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
        $row->auth_password = 'SuperSecret-1234';

        $script = $this->port->buildScript($row, '/etc/redis/redis.conf', 'redis-server', 6390);

        // base64 of 'SuperSecret-1234' is 'U3VwZXJTZWNyZXQtMTIzNA=='. Whatever the encoding,
        // the script should include the -a flag so the verify ping authenticates.
        $this->assertStringContainsString('PASS_B64=', $script);
        $this->assertStringContainsString('redis-cli -a "$PASS" -p 6390 ping', $script);
        $this->assertStringNotContainsString('SuperSecret-1234', $script, 'Plain password must not appear in the script.');
    }

    public function test_memcached_script_strips_active_and_commented_p_lines_and_uses_tcp_check(): void
    {
        $row = $this->row('memcached', ServerCacheService::DEFAULT_INSTANCE_NAME, 11211);
        $script = $this->port->buildScript($row, '/etc/memcached.conf', 'memcached', 11212);

        // The sed has to strip both `^-p ` and `^# -p ` so we don't leave a stale value behind.
        $this->assertStringContainsString("sed -i.tmp -E '/^[[:space:]]*#?[[:space:]]*-p[[:space:]]/d' /etc/memcached.conf", $script);
        $this->assertStringContainsString("printf -- '-p %d\\n' 11212 >> /etc/memcached.conf", $script);
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/11212', $script);
    }

    public function test_dragonfly_script_replaces_port_flag(): void
    {
        $row = $this->row('dragonfly', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
        $script = $this->port->buildScript($row, '/etc/dragonfly/dragonfly.conf', 'dragonfly', 6395);

        $this->assertStringContainsString("sed -i.tmp '/^[[:space:]]*--port=/d' /etc/dragonfly/dragonfly.conf", $script);
        $this->assertStringContainsString("printf -- '--port=%d\\n' 6395 >> /etc/dragonfly/dragonfly.conf", $script);
        $this->assertStringContainsString('redis-cli -p 6395 ping', $script);
    }

    public function test_change_port_rejects_out_of_range_port(): void
    {
        $row = $this->row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);

        $this->expectException(\InvalidArgumentException::class);
        $this->port->changePort($this->fakeServer(), $row, 80);
    }

    public function test_change_port_rejects_same_port(): void
    {
        $row = $this->row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);

        $this->expectException(\InvalidArgumentException::class);
        $this->port->changePort($this->fakeServer(), $row, 6379);
    }

    private function row(string $engine, string $name, int $port): ServerCacheService
    {
        $row = new ServerCacheService;
        $row->engine = $engine;
        $row->name = $name;
        $row->port = $port;
        $row->auth_password = null;

        return $row;
    }

    private function fakeServer(): \App\Models\Server
    {
        return new \App\Models\Server;
    }
}
