<?php

declare(strict_types=1);

use App\Support\Providers\ProviderApiStatus;
use App\Support\Providers\ProviderCatalogCache;
use App\Support\Providers\ProviderCatalogFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('detects curl timeouts and connection exceptions as unreachable', function () {
    $timeout = 'cURL error 28: Operation timed out after 8002 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.digitalocean.com/v2/regions?per_page=200&page=1';

    expect(ProviderCatalogFailure::isUnreachable($timeout))->toBeTrue()
        ->and(ProviderCatalogFailure::isUnreachable(new ConnectionException($timeout)))->toBeTrue()
        ->and(ProviderCatalogFailure::isUnreachable('Unable to authenticate you'))->toBeFalse();
});

test('sanitizes raw curl dumps into operator copy', function () {
    $timeout = 'cURL error 28: Operation timed out after 8002 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.digitalocean.com/v2/regions?per_page=200&page=1';

    expect(ProviderCatalogFailure::sanitize($timeout, 'digitalocean'))
        ->not->toContain('curl.se')
        ->not->toContain('api.digitalocean.com')
        ->toContain('DigitalOcean')
        ->toContain('paused');
});

test('marks a provider unreachable and skips later catalog calls', function () {
    ProviderApiStatus::markUnreachable('digitalocean', 'cURL error 28: Operation timed out');

    expect(ProviderApiStatus::isUnreachable('digitalocean'))->toBeTrue()
        ->and(ProviderApiStatus::isUnreachable('digitalocean_kubernetes'))->toBeTrue()
        ->and(ProviderApiStatus::isUnreachable('hetzner'))->toBeFalse();
});

test('catalog cache returns the stored list without refetching', function () {
    $hits = 0;
    $first = ProviderCatalogCache::remember('digitalocean', 'regions', 'platform', function () use (&$hits) {
        $hits++;

        return [['slug' => 'nyc3']];
    });
    $second = ProviderCatalogCache::remember('digitalocean', 'regions', 'platform', function () use (&$hits) {
        $hits++;

        return [['slug' => 'sfo3']];
    });

    expect($hits)->toBe(1)
        ->and($first)->toBe($second)
        ->and($second[0]['slug'])->toBe('nyc3')
        ->and(ProviderApiStatus::isUnreachable('digitalocean'))->toBeFalse();
});
