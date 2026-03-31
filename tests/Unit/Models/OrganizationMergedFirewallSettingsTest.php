<?php

namespace Tests\Unit\Models;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMergedFirewallSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_firewall_settings_use_config_defaults_when_null(): void
    {
        $org = Organization::factory()->create(['firewall_settings' => null]);
        $merged = $org->mergedFirewallSettings();

        $this->assertIsArray($merged);
        $this->assertArrayHasKey('require_second_approval', $merged);
        $this->assertArrayHasKey('notify_drift_webhook', $merged);
        $this->assertArrayHasKey('synthetic_probe_url', $merged);
        $this->assertSame(
            config('server_firewall.organization_settings.require_second_approval'),
            $merged['require_second_approval']
        );
    }

    public function test_merged_firewall_settings_override_defaults_for_known_keys(): void
    {
        $org = Organization::factory()->create([
            'firewall_settings' => [
                'require_second_approval' => true,
                'synthetic_probe_url' => 'https://example.com/ping',
            ],
        ]);
        $merged = $org->mergedFirewallSettings();

        $this->assertTrue($merged['require_second_approval']);
        $this->assertSame(
            config('server_firewall.organization_settings.notify_drift_webhook'),
            $merged['notify_drift_webhook']
        );
        $this->assertSame('https://example.com/ping', $merged['synthetic_probe_url']);
    }
}
