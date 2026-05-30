<?php

declare(strict_types=1);

use App\Services\Servers\LiveState\TraefikLiveStateProbe;

it('normalizes traefik entrypoints map and http routers from api json', function (): void {
    $probe = new TraefikLiveStateProbe;
    $reflection = new ReflectionClass($probe);

    $entrypoints = $reflection->getMethod('buildEntrypointUnits');
    $entrypoints->setAccessible(true);
    $rows = $entrypoints->invoke($probe, [
        'web' => ['address' => ':80', 'http' => []],
        'traefik' => ['address' => '127.0.0.1:9094'],
    ]);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('web')
        ->and($rows[0]['address'])->toBe(':80');

    $routers = $reflection->getMethod('buildRouterUnits');
    $routers->setAccessible(true);
    $routerRows = $routers->invoke($probe, [
        [
            'name' => 'dply-example@file',
            'rule' => 'Host(`example.test`)',
            'service' => 'dply-example',
            'middlewares' => [],
            'entryPoints' => ['web'],
            'status' => 'enabled',
            'provider' => 'file',
        ],
    ]);
    expect($routerRows[0]['entry_points'])->toBe(['web']);
});

it('builds tcp and tls units from api-shaped payloads', function (): void {
    $probe = new TraefikLiveStateProbe;
    $reflection = new ReflectionClass($probe);

    $tcpRouters = $reflection->getMethod('buildTcpRouterUnits');
    $tcpRouters->setAccessible(true);
    expect($tcpRouters->invoke($probe, [
        ['name' => 'mysql@file', 'rule' => 'HostSNI(`*`)', 'service' => 'mysql', 'entryPoints' => ['web'], 'status' => 'enabled', 'provider' => 'file'],
    ]))->toHaveCount(1);

    $tls = $reflection->getMethod('buildTlsUnits');
    $tls->setAccessible(true);
    $certRows = $tls->invoke($probe, [
        'default' => [
            'certificates' => [
                ['subject' => 'CN=example.test', 'sans' => ['example.test'], 'status' => 'valid'],
            ],
        ],
    ]);
    expect($certRows[0]['subject'])->toBe('CN=example.test')
        ->and($certRows[0]['sans'])->toBe(['example.test']);
});
