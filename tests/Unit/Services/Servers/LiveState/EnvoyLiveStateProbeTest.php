<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Servers\LiveState\EnvoyLiveStateProbe;

test('envoy live state probe returns standby when another edge proxy is active', function (): void {
    $server = Server::factory()->make([
        'meta' => [
            'webserver' => 'caddy',
            'edge_proxy' => 'haproxy',
        ],
    ]);

    $state = app(EnvoyLiveStateProbe::class)->probe($server, forceFresh: true);

    expect($state->engineSpecific['standby'] ?? false)->toBeTrue()
        ->and($state->engineSpecific['standby_reason'] ?? '')->toContain('HAProxy')
        ->and($state->engineSpecific)->not->toHaveKey('errors')
        ->and($state->units)->toBe([]);
});

test('inactive edge proxy gate is skipped when envoy is active', function (): void {
    $server = Server::factory()->make([
        'meta' => [
            'webserver' => 'caddy',
            'edge_proxy' => 'envoy',
        ],
    ]);

    $probe = app(EnvoyLiveStateProbe::class);
    $method = new ReflectionMethod($probe, 'inactiveEdgeProxyLiveState');
    $method->setAccessible(true);

    expect($method->invoke($probe, $server))->toBeNull();
});
