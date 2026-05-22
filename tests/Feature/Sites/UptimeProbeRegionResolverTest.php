<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\UptimeProbeRegionResolverTest;
use App\Services\Sites\UptimeProbeRegionResolver;
test('it maps digitalocean regions to the nearest probe', function () {
    $resolver = new UptimeProbeRegionResolver;

    expect($resolver->resolve('nyc1'))->toBe('us-east');
    expect($resolver->resolve('nyc3'))->toBe('us-east');
    expect($resolver->resolve('tor1'))->toBe('us-east');
    expect($resolver->resolve('sfo3'))->toBe('us-west');
    expect($resolver->resolve('ams3'))->toBe('eu-amsterdam');
    expect($resolver->resolve('fra1'))->toBe('eu-frankfurt');
    expect($resolver->resolve('syd1'))->toBe('ap-sydney');
});
test('an unknown or empty region falls back to the first configured', function () {
    $resolver = new UptimeProbeRegionResolver;
    $first = (string) array_key_first(config('site_uptime.probe_regions'));

    expect($resolver->resolve('mars1'))->toBe($first);
    expect($resolver->resolve(''))->toBe($first);
    expect($resolver->resolve(null))->toBe($first);
});
