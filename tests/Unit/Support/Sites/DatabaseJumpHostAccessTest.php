<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Support\Servers\DatabaseJumpHostAccess;

test('ipInCidr matches IPv4 hosts, subnets, and bare IPs', function (): void {
    expect(DatabaseJumpHostAccess::ipInCidr('10.0.0.4', '10.0.0.4/32'))->toBeTrue()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.0.2', '10.0.0.4/32'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.0.4', '10.0.0.0/24'))->toBeTrue()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.1.4', '10.0.0.0/24'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('178.1.1.1', '10.0.0.0/8'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.255.255.255', '10.0.0.0/8'))->toBeTrue()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.0.4', '10.0.0.4'))->toBeTrue()
        ->and(DatabaseJumpHostAccess::ipInCidr('9.9.9.9', '0.0.0.0/0'))->toBeTrue();
});

test('ipInCidr is safe against junk and v4/v6 mismatch', function (): void {
    expect(DatabaseJumpHostAccess::ipInCidr('', '10.0.0.0/8'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('not-an-ip', '10.0.0.0/8'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.0.4', 'garbage'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('10.0.0.4', '::/0'))->toBeFalse()
        ->and(DatabaseJumpHostAccess::ipInCidr('fd00::1', 'fd00::/8'))->toBeTrue();
});

test('eligibleJumpHosts returns only allowlisted peers, never the db host', function (): void {
    $dbServer = Server::factory()->make(['private_ip_address' => '10.0.0.3', 'ip_address' => '203.0.113.3']);
    $dbServer->id = '01HZJUMPDBSERVER0000001';

    $worker = Server::factory()->make(['name' => 'worker-1', 'private_ip_address' => '10.0.0.4', 'ip_address' => '203.0.113.4']);
    $worker->id = '01HZJUMPWORKER000000001';

    $redis = Server::factory()->make(['name' => 'redis-1', 'private_ip_address' => '10.0.0.2', 'ip_address' => '203.0.113.2']);
    $redis->id = '01HZJUMPREDIS0000000001';

    $db = ServerDatabase::factory()->make([
        'server_id' => $dbServer->id,
        'engine' => 'postgres',
        'name' => 'app',
        'remote_access' => true,
        'allowed_from' => '10.0.0.4/32',
    ]);

    $hosts = DatabaseJumpHostAccess::eligibleJumpHosts($db, $dbServer, collect([$worker, $redis, $dbServer]));

    expect($hosts->pluck('name')->all())->toBe(['worker-1']);
});

test('eligibleJumpHosts is empty when remote access is off or no allowlist', function (): void {
    $dbServer = Server::factory()->make(['private_ip_address' => '10.0.0.3']);
    $dbServer->id = '01HZJUMPDBSERVER0000002';
    $worker = Server::factory()->make(['private_ip_address' => '10.0.0.4', 'ip_address' => '203.0.113.4']);
    $worker->id = '01HZJUMPWORKER000000002';

    $off = ServerDatabase::factory()->make(['server_id' => $dbServer->id, 'remote_access' => false, 'allowed_from' => '10.0.0.4/32']);
    $blank = ServerDatabase::factory()->make(['server_id' => $dbServer->id, 'remote_access' => true, 'allowed_from' => null]);

    expect(DatabaseJumpHostAccess::eligibleJumpHosts($off, $dbServer, collect([$worker])))->toHaveCount(0)
        ->and(DatabaseJumpHostAccess::eligibleJumpHosts($blank, $dbServer, collect([$worker])))->toHaveCount(0);
});

test('commandsFor builds an ssh -L tunnel through the jump host and a client connect', function (): void {
    $dbServer = Server::factory()->make(['private_ip_address' => '10.0.0.3', 'ip_address' => '203.0.113.3']);
    $jump = Server::factory()->make(['ssh_user' => 'dply', 'private_ip_address' => '10.0.0.4', 'ip_address' => '203.0.113.4']);

    $pg = ServerDatabase::factory()->make(['engine' => 'postgres', 'name' => 'app', 'username' => 'app_user']);
    $cmds = DatabaseJumpHostAccess::commandsFor($pg, $dbServer, $jump, 5432, 15432);

    expect($cmds['tunnel'])->toBe('ssh -L 15432:10.0.0.3:5432 dply@203.0.113.4')
        ->and($cmds['connect'])->toContain('psql')
        ->and($cmds['connect'])->toContain('port=15432')
        ->and($cmds['connect'])->toContain('dbname=app');

    $my = ServerDatabase::factory()->make(['engine' => 'mysql', 'name' => 'shop', 'username' => 'shop_user']);
    $myCmds = DatabaseJumpHostAccess::commandsFor($my, $dbServer, $jump, 3306, 15442);

    expect($myCmds['tunnel'])->toBe('ssh -L 15442:10.0.0.3:3306 dply@203.0.113.4')
        ->and($myCmds['connect'])->toContain('mysql')
        ->and($myCmds['connect'])->toContain('-P 15442');
});
