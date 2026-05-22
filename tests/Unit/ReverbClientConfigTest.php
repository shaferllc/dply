<?php


namespace Tests\Unit\ReverbClientConfigTest;
use App\Support\ReverbClientConfig;
use PHPUnit\Framework\Attributes\DataProvider;

test('browser port', function (?string $explicitPort, string $scheme, ?string $serverPort, string $appEnvironment, int $expected) {
    expect(ReverbClientConfig::browserPort($explicitPort, $scheme, $serverPort, $appEnvironment))->toBe($expected);
})->with('browserPortProvider');

/**
 * @return iterable<string, array{0: ?string, 1: string, 2: ?string, 3: string, 4: int}>
 */
dataset('browserPortProvider', function () {
    yield 'https testing uses server port (no nginx on 443)' => [
        null,
        'https',
        '8081',
        'testing',
        8081,
    ];

    yield 'https local uses server port' => [
        null,
        'https',
        '8081',
        'local',
        8081,
    ];

    yield 'https production defaults to 443' => [
        null,
        'https',
        '8081',
        'production',
        443,
    ];

    yield 'explicit REVERB_PORT wins over https production' => [
        '8443',
        'https',
        '8081',
        'production',
        8443,
    ];

    yield 'explicit 443 for reverse proxy' => [
        '443',
        'https',
        '8081',
        'testing',
        443,
    ];

    yield 'http uses server port in production' => [
        null,
        'http',
        '8081',
        'production',
        8081,
    ];

    yield 'http uses default server 8080 when unset' => [
        null,
        'http',
        null,
        'local',
        8080,
    ];

    yield 'empty scheme treated as http' => [
        null,
        '',
        '9090',
        'local',
        9090,
    ];
});
