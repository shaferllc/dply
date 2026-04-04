<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\SiteNginxProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteNginxProvisionerLayeredSnippetTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_layered_main_snippet_round_trips_for_php_site(): void
    {
        $site = Site::factory()->create([
            'nginx_extra_raw' => null,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.test',
            'is_primary' => true,
        ]);

        $profile = SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
            'before_body' => '# before test',
            'main_snippet_body' => "location = /ping {\n    return 200 'ok';\n}",
            'after_body' => '# after test',
        ]);

        $builder = app(NginxSiteConfigBuilder::class);
        $config = $builder->build($site->fresh(), $profile);

        $provisioner = app(SiteNginxProvisioner::class);
        $parsed = $provisioner->parseLayeredMainSnippetFromVhost($site->fresh(), $config);

        $this->assertSame(trim((string) $profile->main_snippet_body), trim((string) $parsed));
    }
}
