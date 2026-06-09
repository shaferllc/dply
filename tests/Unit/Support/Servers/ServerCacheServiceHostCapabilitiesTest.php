<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Support\Facades\Cache;

test('unsupported reasons by engine reads distro cache once per server', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => 'test-key',
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with(
            'server.'.$server->id.'.cache_service_distro_v1',
            Mockery::any(),
            Mockery::type('Closure'),
        )
        ->andReturn(['id' => 'ubuntu', 'codename' => 'noble']);

    $capabilities = new ServerCacheServiceHostCapabilities(
        Mockery::mock(ServerSshConnectionRunner::class),
    );

    $reasons = $capabilities->unsupportedReasonsByEngine($server);

    expect($reasons['redis'])->toBeNull()
        ->and($reasons['keydb'])->toContain('KeyDB upstream')
        ->and($reasons['dragonfly'])->toBeNull();
});
