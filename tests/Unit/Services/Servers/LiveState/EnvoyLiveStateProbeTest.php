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

test('envoy live state probe parses nested listener socket addresses', function (): void {
    $probe = app(EnvoyLiveStateProbe::class);
    $method = new ReflectionMethod($probe, 'buildListenerUnits');
    $method->setAccessible(true);

    $blob = json_encode([
        'listener_statuses' => [
            [
                'name' => 'http_ingress',
                'local_address' => [
                    'socket_address' => [
                        'address' => [
                            'socket_address' => [
                                'address' => '0.0.0.0',
                                'port_value' => 80,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'admin',
                'local_address' => [
                    'pipe' => ['path' => '/var/run/envoy-admin.sock'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $rows = $method->invoke($probe, $blob);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('http_ingress')
        ->and($rows[0]['address'])->toBe('0.0.0.0:80')
        ->and($rows[1]['name'])->toBe('admin')
        ->and($rows[1]['address'])->toBe('/var/run/envoy-admin.sock');
});

test('envoy live state probe parses cluster host health without array conversion errors', function (): void {
    $probe = app(EnvoyLiveStateProbe::class);
    $method = new ReflectionMethod($probe, 'buildClusterUnits');
    $method->setAccessible(true);

    $blob = json_encode([
        'cluster_statuses' => [
            [
                'name' => 'caddy_backend',
                'host_statuses' => [
                    [
                        'address' => [
                            'socket_address' => [
                                'address' => '127.0.0.1',
                                'port_value' => 8080,
                            ],
                        ],
                        'health_status' => ['healthy' => true],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $rows = $method->invoke($probe, $blob);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('caddy_backend')
        ->and($rows[0]['status'])->toBe('UP')
        ->and($rows[0]['servers'][0]['status'])->toBe('UP')
        ->and($rows[0]['servers'][0]['name'])->toBe('127.0.0.1:8080');
});

test('envoy live state probe treats static cluster hosts as up when envoy reports no failure flags', function (): void {
    $probe = app(EnvoyLiveStateProbe::class);
    $method = new ReflectionMethod($probe, 'buildClusterUnits');
    $method->setAccessible(true);

    $blob = json_encode([
        'cluster_statuses' => [
            [
                'name' => 'cluster_dply_01kswr88bnn6fwtnfb1dcp9czw_sdfsfsfs',
                'host_statuses' => [
                    [
                        'address' => [
                            'socket_address' => [
                                'address' => '127.0.0.1',
                                'port_value' => 30245,
                            ],
                        ],
                        'health_status' => [
                            'failed_active_health_check' => false,
                            'failed_outlier_check' => false,
                        ],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $rows = $method->invoke($probe, $blob);

    expect($rows[0]['status'])->toBe('UP')
        ->and($rows[0]['servers'][0]['status'])->toBe('UP')
        ->and($rows[0]['sessions_current'])->toBe(1);
});
