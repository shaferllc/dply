<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\CacheServicePortTest;
use Mockery;

use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServicePort;
beforeEach(function () {
    $this->port = new CacheServicePort(Mockery::mock(ExecuteRemoteTaskOnServer::class));
});
test('redis default instance script replaces port directive', function () {
    $row = row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
    $script = $this->port->buildScript($row, '/etc/redis/redis.conf', 'redis-server', 6390);

    $this->assertStringContainsString("sed -i.tmp '/^[[:space:]]*port[[:space:]]/d' /etc/redis/redis.conf", $script);
    $this->assertStringContainsString("printf 'port %d\\n' 6390 >> /etc/redis/redis.conf", $script);
    $this->assertStringContainsString('systemctl restart redis-server', $script);
    $this->assertStringContainsString('redis-cli -p 6390 ping', $script);
});
test('valkey named instance script uses templated unit and cli', function () {
    $row = row('valkey', 'sessions', 6380);
    $script = $this->port->buildScript($row, '/etc/valkey/valkey-sessions.conf', 'valkey-server@sessions', 6395);

    $this->assertStringContainsString('systemctl restart valkey-server@sessions', $script);
    $this->assertStringContainsString('valkey-cli -p 6395 ping', $script);
    $this->assertStringContainsString("printf 'port %d\\n' 6395 >> /etc/valkey/valkey-sessions.conf", $script);
});
test('keydb uses keydb cli for verification', function () {
    $row = row('keydb', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
    $script = $this->port->buildScript($row, '/etc/keydb/keydb.conf', 'keydb-server', 6391);

    $this->assertStringContainsString('keydb-cli -p 6391 ping', $script);
});
test('redis with auth password passes it to verify ping', function () {
    $row = row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
    $row->auth_password = 'SuperSecret-1234';

    $script = $this->port->buildScript($row, '/etc/redis/redis.conf', 'redis-server', 6390);

    // base64 of 'SuperSecret-1234' is 'U3VwZXJTZWNyZXQtMTIzNA=='. Whatever the encoding,
    // the script should include the -a flag so the verify ping authenticates.
    $this->assertStringContainsString('PASS_B64=', $script);
    $this->assertStringContainsString('redis-cli -a "$PASS" -p 6390 ping', $script);
    $this->assertStringNotContainsString('SuperSecret-1234', $script, 'Plain password must not appear in the script.');
});
test('memcached script strips active and commented p lines and uses tcp check', function () {
    $row = row('memcached', ServerCacheService::DEFAULT_INSTANCE_NAME, 11211);
    $script = $this->port->buildScript($row, '/etc/memcached.conf', 'memcached', 11212);

    // The sed has to strip both `^-p ` and `^# -p ` so we don't leave a stale value behind.
    $this->assertStringContainsString("sed -i.tmp -E '/^[[:space:]]*#?[[:space:]]*-p[[:space:]]/d' /etc/memcached.conf", $script);
    $this->assertStringContainsString("printf -- '-p %d\\n' 11212 >> /etc/memcached.conf", $script);
    $this->assertStringContainsString('/dev/tcp/127.0.0.1/11212', $script);
});
test('dragonfly script replaces port flag', function () {
    $row = row('dragonfly', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);
    $script = $this->port->buildScript($row, '/etc/dragonfly/dragonfly.conf', 'dragonfly', 6395);

    $this->assertStringContainsString("sed -i.tmp '/^[[:space:]]*--port=/d' /etc/dragonfly/dragonfly.conf", $script);
    $this->assertStringContainsString("printf -- '--port=%d\\n' 6395 >> /etc/dragonfly/dragonfly.conf", $script);
    $this->assertStringContainsString('redis-cli -p 6395 ping', $script);
});
test('change port rejects out of range port', function () {
    $row = row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);

    $this->expectException(\InvalidArgumentException::class);
    $this->port->changePort(fakeServer(), $row, 80);
});
test('change port rejects same port', function () {
    $row = row('redis', ServerCacheService::DEFAULT_INSTANCE_NAME, 6379);

    $this->expectException(\InvalidArgumentException::class);
    $this->port->changePort(fakeServer(), $row, 6379);
});
function row(string $engine, string $name, int $port): ServerCacheService
{
    $row = new ServerCacheService;
    $row->engine = $engine;
    $row->name = $name;
    $row->port = $port;
    $row->auth_password = null;

    return $row;
}
function fakeServer(): \App\Models\Server
{
    return new \App\Models\Server;
}
