<?php


namespace Tests\Unit\Services\SiteNginxProvisionerLayeredSnippetTest;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\SiteNginxProvisioner;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('parse layered main snippet round trips for php site', function () {
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

    expect(trim((string) $parsed))->toBe(trim((string) $profile->main_snippet_body));
});