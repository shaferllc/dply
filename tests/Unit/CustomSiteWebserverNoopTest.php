<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DockerRuntimeDockerfileBuilder;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom sites have no webserver vhost rendered by dply. These regressions
 * pin that contract — any builder that learns to handle Custom must stay
 * a no-op (empty string), not throw an UnhandledMatchError, and not emit
 * a partial config.
 */
final class CustomSiteWebserverNoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_nginx_builder_returns_empty_for_custom(): void
    {
        $site = $this->customSite();
        $config = app(NginxSiteConfigBuilder::class)->build($site);
        $this->assertSame('', $config);
    }

    public function test_apache_builder_returns_empty_for_custom(): void
    {
        $site = $this->customSite();
        $config = app(ApacheSiteConfigBuilder::class)->build($site);
        $this->assertSame('', $config);
    }

    public function test_caddy_builder_returns_empty_for_custom(): void
    {
        $site = $this->customSite();
        $config = app(CaddySiteConfigBuilder::class)->build($site);
        $this->assertSame('', $config);
    }

    public function test_openlitespeed_builder_returns_empty_for_custom(): void
    {
        $site = $this->customSite();
        $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
        $this->assertSame('', $config);
    }

    public function test_dockerfile_builder_returns_empty_for_custom(): void
    {
        $site = $this->customSite();
        $dockerfile = app(DockerRuntimeDockerfileBuilder::class)->build($site);
        $this->assertSame('', $dockerfile);
    }

    private function customSite(): Site
    {
        $server = Server::factory()->ready()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
        ]);

        return Site::factory()->custom()->create(['server_id' => $server->id]);
    }
}
