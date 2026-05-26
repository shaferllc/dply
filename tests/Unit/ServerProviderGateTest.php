<?php

namespace Tests\Unit\ServerProviderGateTest;

use App\Support\ServerProviderGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
usesFeatures('provider.vultr', 'provider.upcloud', 'provider.linode');

test('defaults enable digitalocean hetzner vultr linode upcloud and custom', function () {
    expect(ServerProviderGate::enabled('digitalocean'))->toBeTrue();
    expect(ServerProviderGate::enabled('hetzner'))->toBeTrue();
    expect(ServerProviderGate::enabled('vultr'))->toBeTrue();
    expect(ServerProviderGate::enabled('linode'))->toBeTrue();
    expect(ServerProviderGate::enabled('upcloud'))->toBeTrue();
    expect(ServerProviderGate::enabled('custom'))->toBeTrue();
});

test('default server create type respects flags', function () {
    config(['server_providers.enabled.digitalocean' => true]);
    config(['server_providers.enabled.custom' => true]);
    config(['server_providers.enabled.hetzner' => false]);

    expect(ServerProviderGate::defaultServerCreateType())->toBe('digitalocean');
});

test('default server create type skips disabled digitalocean', function () {
    // Disable the entire DO family — droplets, functions, kubernetes — and
    // pin aws_app_runner off, since some local installs enable it.
    config(['server_providers.enabled.digitalocean' => false]);
    config(['server_providers.enabled.digitalocean_functions' => false]);
    config(['server_providers.enabled.digitalocean_kubernetes' => false]);
    config(['server_providers.enabled.aws_app_runner' => false]);
    config(['server_providers.enabled.hetzner' => true]);
    config(['server_providers.enabled.custom' => true]);

    expect(ServerProviderGate::defaultServerCreateType())->toBe('hetzner');
});
