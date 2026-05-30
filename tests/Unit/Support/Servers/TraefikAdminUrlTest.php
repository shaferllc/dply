<?php

declare(strict_types=1);

use App\Support\Servers\TraefikAdminUrl;

test('traefik admin url resolves entry point address', function (string $address, ?string $expected): void {
    expect(TraefikAdminUrl::fromAddress($address))->toBe($expected);
})->with([
    ['127.0.0.1:9094', 'http://127.0.0.1:9094'],
    [':9094', 'http://127.0.0.1:9094'],
    ['localhost:9094', 'http://127.0.0.1:9094'],
]);

test('traefik admin url reads static config entry point', function (): void {
    $url = TraefikAdminUrl::fromStaticConfig([
        'entryPoints' => [
            'traefik' => ['address' => '127.0.0.1:9094'],
        ],
        'api' => ['dashboard' => true, 'insecure' => true],
    ]);

    expect($url)->toBe('http://127.0.0.1:9094')
        ->and(TraefikAdminUrl::apiDashboardEnabled(['api' => ['dashboard' => true]]))->toBeTrue()
        ->and(TraefikAdminUrl::apiInsecureEnabled(['api' => ['insecure' => true]]))->toBeTrue();
});

test('traefik admin url treats omitted api flags as enabled when traefik entry point exists', function (): void {
    $parsed = [
        'entryPoints' => [
            'traefik' => ['address' => '127.0.0.1:9094'],
        ],
    ];

    expect(TraefikAdminUrl::apiDashboardEnabled($parsed))->toBeTrue()
        ->and(TraefikAdminUrl::apiInsecureEnabled($parsed))->toBeTrue();
});

test('traefik admin url isTruthy handles yaml string booleans', function (): void {
    expect(TraefikAdminUrl::isTruthy('true'))->toBeTrue()
        ->and(TraefikAdminUrl::isTruthy('false'))->toBeFalse();
});
