<?php

namespace Tests\Unit;

use App\Support\ServerProviderGate;
use Tests\TestCase;

class ServerProviderGateTest extends TestCase
{
    public function test_defaults_enable_digitalocean_and_custom(): void
    {
        $this->assertTrue(ServerProviderGate::enabled('digitalocean'));
        $this->assertTrue(ServerProviderGate::enabled('custom'));
        $this->assertFalse(ServerProviderGate::enabled('hetzner'));
    }

    public function test_default_server_create_type_respects_flags(): void
    {
        config(['server_providers.enabled.digitalocean' => true]);
        config(['server_providers.enabled.custom' => true]);
        config(['server_providers.enabled.hetzner' => false]);

        $this->assertSame('digitalocean', ServerProviderGate::defaultServerCreateType());
    }

    public function test_default_server_create_type_skips_disabled_digitalocean(): void
    {
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

        $this->assertSame('hetzner', ServerProviderGate::defaultServerCreateType());
    }
}
