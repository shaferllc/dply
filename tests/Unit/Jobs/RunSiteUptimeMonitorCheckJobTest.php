<?php


namespace Tests\Unit\Jobs\RunSiteUptimeMonitorCheckJobTest;
use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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