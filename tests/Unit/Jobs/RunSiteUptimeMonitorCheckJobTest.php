<?php

namespace Tests\Unit\Jobs\RunSiteUptimeMonitorCheckJobTest;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('job records successful check', function () {
    Http::fake(fn () => Http::response('ok', 200));

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeTrue();
    expect($monitor->last_http_status)->toBe(200);
    expect($monitor->last_checked_at)->not->toBeNull();
});

test('an https check does not fall back to http', function () {
    Http::fake([
        'https://app.example.test' => Http::response('fail', 500),
        'http://app.example.test' => Http::response('ok', 200),
    ]);

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeFalse();
    expect($monitor->last_http_status)->toBe(500);

    Http::assertSent(fn ($request) => $request->url() === 'https://app.example.test');
    Http::assertNotSent(fn ($request) => $request->url() === 'http://app.example.test');
});

test('an http check does not fall back to https', function () {
    Http::fake([
        'https://app.example.test' => Http::response('ok', 200),
        'http://app.example.test' => Http::response('fail', 500),
    ]);

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'check_type' => SiteUptimeMonitor::CHECK_HTTP,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeFalse();
    expect($monitor->last_http_status)->toBe(500);

    Http::assertSent(fn ($request) => $request->url() === 'http://app.example.test');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://app.example.test');
});
