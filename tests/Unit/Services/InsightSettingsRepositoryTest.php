<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Services\Insights\InsightSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightSettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_enabled_map_turns_off_pro_insights_without_subscription(): void
    {
        // Heartbeat default_enabled is env-driven (INSIGHTS_HEARTBEAT_DEFAULT_ENABLED).
        // Pin it to false here so the test doesn't depend on the developer's local env.
        config(['insights.insights.insights_pipeline_heartbeat.default_enabled' => false]);

        $org = Organization::factory()->create();
        $repo = new InsightSettingsRepository;

        $map = $repo->defaultEnabledMap($org);

        $this->assertArrayHasKey('npm_vulnerabilities', $map);
        $this->assertFalse($map['npm_vulnerabilities']);
        $this->assertArrayHasKey('cpu_ram_usage', $map);
        $this->assertTrue($map['cpu_ram_usage']);
        $this->assertArrayHasKey('insights_pipeline_heartbeat', $map);
        $this->assertFalse($map['insights_pipeline_heartbeat']);
    }
}
