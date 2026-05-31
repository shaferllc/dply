<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

test('config file includes bind requirepass and ufw for remote authenticated redis host', function () {
    $password = 'SuperSecret-CachePass99';
    $server = Server::factory()->create([
        'meta' => [
            'server_role' => 'redis',
            'cache_service' => 'redis',
            'cache_server' => [
                'remote_access' => true,
                'allowed_from' => '10.0.0.0/8',
                'require_password' => true,
                'password_encrypted' => Crypt::encryptString($password),
            ],
        ],
    ]);

    $config = DedicatedCacheServerProvisionConfig::fromServer($server, 'redis');

    expect($config->configFileContent('redis'))
        ->toContain('bind 0.0.0.0 -::1')
        ->toContain('requirepass '.$password);

    expect($config->ufwAllowLines())->toBe([
        "ufw allow from '10.0.0.0/8' to any port 6379 proto tcp",
    ]);
});

test('rejects public internet cidr', function () {
    expect(DedicatedCacheServerProvisionConfig::isAllowedSourceCidr('0.0.0.0/0'))->toBeFalse();
    expect(DedicatedCacheServerProvisionConfig::isAllowedSourceCidr('10.20.0.0/16'))->toBeTrue();
});

test('localhost defaults when meta absent', function () {
    $config = DedicatedCacheServerProvisionConfig::fromServer(null, 'valkey');

    expect($config->configFileContent('valkey'))->toContain('bind 127.0.0.1 ::1');
    expect($config->ufwAllowLines())->toBe([]);
});
