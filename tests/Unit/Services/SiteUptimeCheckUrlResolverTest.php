<?php

namespace Tests\Unit\Services\SiteUptimeCheckUrlResolverTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('resolves full url from primary domain and path', function () {
    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'path' => '/health',
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
    ]);

    $resolver = app(SiteUptimeCheckUrlResolver::class);

    expect($resolver->resolveFullUrl($site->fresh(), $monitor))->toBe('https://app.example.test/health');
});

test('resolves base url from runtime publication url', function () {
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

    expect($resolver->resolveBaseUrl($site))->toBe('http://orb.local:8080');
});

test('full url honors the monitor check scheme', function () {
    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $https = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'path' => '/health',
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
    ]);
    $http = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'path' => '/health',
        'check_type' => SiteUptimeMonitor::CHECK_HTTP,
        'sort_order' => 1,
    ]);

    $resolver = app(SiteUptimeCheckUrlResolver::class);

    expect($resolver->resolveFullUrl($site->fresh(), $https))->toBe('https://app.example.test/health');
    expect($resolver->resolveFullUrl($site->fresh(), $http))->toBe('http://app.example.test/health');
});
