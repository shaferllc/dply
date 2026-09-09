<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CloudflareApexRecordNameTest;

use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use ReflectionMethod;

function fqdn(string $zone, string $name): string
{
    $method = new ReflectionMethod(CloudflareDnsService::class, 'fqdn');
    $method->setAccessible(true);

    // Constructed with a dummy token: fqdn() is pure string work and never
    // reaches the API, so no HTTP faking is needed.
    return $method->invoke(new CloudflareDnsService('test-token'), $zone, $name);
}

test('the apex marker resolves to the bare zone, not @.zone', function () {
    // ApplySiteDnsRecordsJob emits '@' for a hostname equal to the zone. This
    // built '@.outbidpixels.com' and Cloudflare rejected the whole apply with
    // "DNS name is invalid".
    expect(fqdn('outbidpixels.com', '@'))->toBe('outbidpixels.com')
        ->and(fqdn('outbidpixels.com', ''))->toBe('outbidpixels.com');
});

test('subdomains and already-qualified names are unchanged', function () {
    expect(fqdn('outbidpixels.com', 'www'))->toBe('www.outbidpixels.com')
        ->and(fqdn('outbidpixels.com', 'WWW'))->toBe('www.outbidpixels.com')
        ->and(fqdn('outbidpixels.com', 'www.outbidpixels.com'))->toBe('www.outbidpixels.com')
        ->and(fqdn('outbidpixels.com', 'www.outbidpixels.com.'))->toBe('www.outbidpixels.com');
});
