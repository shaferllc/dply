<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunSiteUptimeMonitorCheckJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_records_successful_check(): void
    {
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

        (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(app(SiteUptimeCheckUrlResolver::class));

        $monitor->refresh();
        $this->assertTrue($monitor->last_ok);
        $this->assertSame(200, $monitor->last_http_status);
        $this->assertNotNull($monitor->last_checked_at);
    }
}
