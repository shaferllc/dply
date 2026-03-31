<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NginxSiteConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_site_access_and_error_log_paths_are_included(): void
    {
        $site = Site::factory()->create([
            'slug' => 'my-app',
            'type' => SiteType::Static,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $basename = $site->nginxConfigBasename();
        $this->assertStringContainsString('access_log /var/log/nginx/'.$basename.'-access.log;', $nginx);
        $this->assertStringContainsString('error_log /var/log/nginx/'.$basename.'-error.log;', $nginx);
    }
}
