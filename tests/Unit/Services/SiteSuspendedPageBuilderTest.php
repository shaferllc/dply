<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Sites\SiteSuspendedPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSuspendedPageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_escapes_site_name_and_includes_optional_message_from_meta(): void
    {
        $site = Site::factory()->create([
            'name' => 'Test & Co',
            'meta' => ['suspended_message' => 'Contact <support@example.com>'],
        ]);

        $html = (new SiteSuspendedPageBuilder)->render($site);

        $this->assertStringContainsString('Test &amp; Co', $html);
        $this->assertStringContainsString('Contact &lt;support@example.com&gt;', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_suspended_public_message_prefers_meta_over_legacy_column(): void
    {
        $site = Site::factory()->create([
            'suspended_reason' => 'Legacy column',
            'meta' => ['suspended_message' => 'From meta'],
        ]);

        $this->assertSame('From meta', $site->suspendedPublicMessage());
    }

    public function test_suspended_public_message_falls_back_to_legacy_column(): void
    {
        $site = Site::factory()->create([
            'suspended_reason' => 'Legacy only',
            'meta' => [],
        ]);

        $this->assertSame('Legacy only', $site->suspendedPublicMessage());
    }
}
