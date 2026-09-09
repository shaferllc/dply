<?php

declare(strict_types=1);

use App\Support\Providers\ProviderAuthFailure;

test('detects digitalocean authenticate-you and http 401', function () {
    expect(ProviderAuthFailure::detected(
        'DigitalOcean API failed to create database cluster: Unable to authenticate you (sent engine=mysql version= region=sfo2 size=db-s-1vcpu-2gb)'
    ))->toBeTrue()
        ->and(ProviderAuthFailure::detected('nope', 401))->toBeTrue()
        ->and(ProviderAuthFailure::detected('unauthorized'))->toBeTrue();
});

test('does not treat region or size errors as auth failures', function () {
    expect(ProviderAuthFailure::detected("DigitalOcean API failed to create database cluster: region 'sfo3' is not valid"))->toBeFalse()
        ->and(ProviderAuthFailure::detected('size is not available in this region'))->toBeFalse()
        ->and(ProviderAuthFailure::detected(null))->toBeFalse()
        ->and(ProviderAuthFailure::detected(''))->toBeFalse();
});

test('operator copy is provider-agnostic', function (string $provider, string $label) {
    expect(ProviderAuthFailure::providerLabel($provider))->toBe($label)
        ->and(ProviderAuthFailure::title($provider))->toContain($label)
        ->and(ProviderAuthFailure::message($provider))->toContain($label)
        ->and(ProviderAuthFailure::message($provider))->toContain('new token');
})->with([
    ['digitalocean', 'DigitalOcean'],
    ['hetzner', 'Hetzner'],
    ['vultr', 'Vultr'],
    ['aws', 'AWS'],
    ['unknown', 'The provider'],
]);
