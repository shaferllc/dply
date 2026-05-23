<?php

namespace Tests\Unit\ServerProviderGateTest;

use App\Support\ServerProviderGate;

test('defaults enable digitalocean hetzner and custom', function () {
    expect(ServerProviderGate::enabled('digitalocean'))->toBeTrue();
    expect(ServerProviderGate::enabled('hetzner'))->toBeTrue();
    expect(ServerProviderGate::enabled('custom'))->toBeTrue();
    expect(ServerProviderGate::enabled('linode'))->toBeFalse();
});

test('default server create type respects flags', function () {
    config(['server_providers.enabled.digitalocean' => true]);
    config(['server_providers.enabled.custom' => true]);
    config(['server_providers.enabled.hetzner' => false]);

    expect(ServerProviderGate::defaultServerCreateType())->toBe('digitalocean');
});

test('default server create type skips disabled digitalocean', function () {
    // Disable the entire DO family — including digitalocean_app_platform,
    // which sits between the kubernetes entry and hetzner in
    // SERVER_CREATE_ORDER and is enabled in some local installs.
    // Also pin aws_app_runner off for the same reason.
    config(['server_providers.enabled.digitalocean' => false]);
    config(['server_providers.enabled.digitalocean_functions' => false]);
    config(['server_providers.enabled.digitalocean_kubernetes' => false]);
    config(['server_providers.enabled.digitalocean_app_platform' => false]);
    config(['server_providers.enabled.aws_app_runner' => false]);
    config(['server_providers.enabled.hetzner' => true]);
    config(['server_providers.enabled.custom' => true]);

    expect(ServerProviderGate::defaultServerCreateType())->toBe('hetzner');
});
