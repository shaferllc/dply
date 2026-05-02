<?php

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRuntimeSettingsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_should_show_php_octane_when_site_type_is_php(): void
    {
        $server = Server::factory()->ready()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'type' => SiteType::Php,
            'meta' => null,
        ]);

        $this->assertTrue($site->shouldShowPhpOctaneRolloutSettings());
    }

    public function test_should_show_rails_when_detection_framework_is_rails(): void
    {
        $server = Server::factory()->ready()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'type' => SiteType::Node,
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'rails',
                        'language' => 'ruby',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($site->shouldShowRailsRuntimeSettings());
        $this->assertFalse($site->shouldShowPhpOctaneRolloutSettings());
    }
}
