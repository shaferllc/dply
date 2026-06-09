<?php

declare(strict_types=1);

use App\Support\Servers\CaddyPhpFpmUpstreamAddress;

test('parses php version from caddy admin unix upstream addresses', function (string $address, ?string $expected): void {
    expect(CaddyPhpFpmUpstreamAddress::phpVersionFromUpstream($address))->toBe($expected);
    expect(CaddyPhpFpmUpstreamAddress::isPhpFpmSocket($address))->toBe($expected !== null);
})->with([
    ['unix///run/php/php8.3-fpm.sock', '8.3'],
    ['unix//run/php/php8.2-fpm.sock', '8.2'],
    ['/run/php/php7.4-fpm.sock', '7.4'],
    ['127.0.0.1:9000', null],
    ['unix///var/run/docker.sock', null],
]);

test('repair php versions uses upstream when installed', function (): void {
    $resolved = CaddyPhpFpmUpstreamAddress::repairPhpVersions(
        'unix:///run/php/php8.3-fpm.sock',
        ['8.3', '8.4'],
        '8.4',
    );

    expect($resolved)->toBe([
        'primary' => '8.3',
        'fallback' => null,
        'upstream' => '8.3',
        'latest_installed' => '8.4',
        'upstream_installed' => true,
        'needs_config_update' => false,
    ]);
});

test('repair php versions falls back to latest installed when upstream missing', function (): void {
    $resolved = CaddyPhpFpmUpstreamAddress::repairPhpVersions(
        'unix:///run/php/php8.3-fpm.sock',
        ['8.4'],
        '8.4',
    );

    expect($resolved['primary'])->toBe('8.4')
        ->and($resolved['upstream'])->toBe('8.3')
        ->and($resolved['upstream_installed'])->toBeFalse()
        ->and($resolved['needs_config_update'])->toBeTrue();
});

test('normalize upstream address dedupes caddy admin variants', function (): void {
    expect(CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress('//run/php/php8.3-fpm.sock'))
        ->toBe(CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress('unix///run/php/php8.3-fpm.sock'));
});

test('repair php versions uses latest when upstream is not parseable', function (): void {
    $resolved = CaddyPhpFpmUpstreamAddress::repairPhpVersions(
        '127.0.0.1:9000',
        ['8.4'],
        '8.4',
    );

    expect($resolved['primary'])->toBe('8.4')
        ->and($resolved['fallback'])->toBeNull()
        ->and($resolved['upstream'])->toBeNull();
});

test('rewrite upstream replaces stale php version', function (): void {
    $rewritten = CaddyPhpFpmUpstreamAddress::rewriteUpstreamToVersion(
        'unix:///run/php/php8.3-fpm.sock',
        '8.4',
    );

    expect($rewritten)->toBe('unix:///run/php/php8.4-fpm.sock');
});
