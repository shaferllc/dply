<?php

declare(strict_types=1);

use App\Models\Server;
use App\Support\Servers\SystemdServiceStandbyReasonResolver;

test('nginx inactive when caddy is active webserver', function () {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'caddy'],
    ]);

    $reason = app(SystemdServiceStandbyReasonResolver::class)
        ->reasonForUnit($server, 'nginx.service', 'inactive');

    expect($reason)->toContain('Caddy')
        ->and($reason)->toContain('webserver');
});

test('caddy inactive when nginx is active webserver', function () {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'nginx'],
    ]);

    $reason = app(SystemdServiceStandbyReasonResolver::class)
        ->reasonForUnit($server, 'caddy.service', 'inactive');

    expect($reason)->toContain('nginx');
});

test('no standby reason when unit is active', function () {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'caddy'],
    ]);

    expect(app(SystemdServiceStandbyReasonResolver::class)->reasonForUnit($server, 'nginx.service', 'active'))
        ->toBeNull();
});

test('no standby reason for unrelated units', function () {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'caddy'],
    ]);

    expect(app(SystemdServiceStandbyReasonResolver::class)->reasonForUnit($server, 'redis-server.service', 'inactive'))
        ->toBeNull();
});

test('inactive engine hint names active webserver', function () {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'caddy'],
    ]);

    $hint = app(SystemdServiceStandbyReasonResolver::class)->inactiveEngineHint($server, 'nginx', false);

    expect($hint)->toContain('Caddy')
        ->and($hint)->toContain('nginx');
});

test('haproxy inactive when traefik is active edge proxy', function () {
    $server = Server::factory()->make();
    $server->forceFill(['meta' => ['edge_proxy' => 'traefik']]);

    $reason = app(SystemdServiceStandbyReasonResolver::class)
        ->reasonForUnit($server, 'haproxy.service', 'inactive');

    expect($reason)->toContain('Traefik')
        ->and($reason)->toContain('edge proxy');
});
