<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http\PublicOutboundUrlTest;

use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;

test('it accepts a public IPv4 origin', function () {
    $safe = PublicOutboundUrl::parse('https://1.1.1.1/health');

    expect($safe->host)->toBe('1.1.1.1')
        ->and($safe->port)->toBe(443)
        ->and($safe->pinIp)->toBe('1.1.1.1')
        ->and($safe->httpClientOptions()['allow_redirects'])->toBeFalse();
});

test('it rejects private loopback link-local and metadata targets', function (string $url) {
    expect(fn () => PublicOutboundUrl::parse($url))
        ->toThrow(UnsafeOutboundUrlException::class);
})->with([
    'http://127.0.0.1/',
    'http://127.0.0.1:8080/latest/meta-data',
    'http://[::1]/',
    'http://10.0.0.8/health',
    'http://192.168.1.10/',
    'http://172.16.0.4/',
    'http://169.254.169.254/latest/meta-data',
    'http://100.64.0.1/',
    'http://0.0.0.0/',
    'http://localhost/health',
    'http://metadata.google.internal/',
    'file:///etc/passwd',
    'http://user:pass@1.1.1.1/',
]);

test('isBlockedIp flags reserved and cgnat ranges', function (string $ip) {
    expect(PublicOutboundUrl::isBlockedIp($ip))->toBeTrue();
})->with([
    '127.0.0.1',
    '10.1.2.3',
    '192.168.0.1',
    '169.254.169.254',
    '100.64.1.2',
    '::1',
    'fe80::1',
    'fc00::1',
    '::ffff:127.0.0.1',
]);

test('isBlockedIp allows public addresses', function (string $ip) {
    expect(PublicOutboundUrl::isBlockedIp($ip))->toBeFalse();
})->with([
    '1.1.1.1',
    '8.8.8.8',
    '93.184.216.34',
]);
