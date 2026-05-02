<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUptimeCheckUrlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_full_url_from_primary_domain_and_path(): void
    {
        $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.test',
            'is_primary' => true,
        ]);

        $monitor = SiteUptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'path' => '/health',
        ]);

        $resolver = app(SiteUptimeCheckUrlResolver::class);

        $this->assertSame('https://app.example.test/health', $resolver->resolveFullUrl($site->fresh(), $monitor));
    }

    public function test_resolves_base_url_from_runtime_publication_url(): void
    {
        $site = Site::factory()->create([
            'status' => Site::STATUS_DOCKER_CONFIGURED,
            'meta' => [
                'runtime_target' => [
                    'family' => 'docker',
                    'publication' => [
                        'url' => 'http://orb.local:8080',
                    ],
                ],
            ],
        ]);

        $resolver = app(SiteUptimeCheckUrlResolver::class);

        $this->assertSame('http://orb.local:8080', $resolver->resolveBaseUrl($site));
    }
}
