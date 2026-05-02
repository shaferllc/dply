<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLatestDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_deployment_returns_most_recent_by_started_at(): void
    {
        $site = $this->makeSite();
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'old',
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subHour(),
        ]);
        $latest = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'newer',
            'trigger' => 'webhook',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subMinute(),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'older',
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_FAILED,
            'started_at' => now()->subHours(2),
        ]);

        $found = $site->latestDeployment();

        $this->assertNotNull($found);
        $this->assertSame($latest->id, $found->id);
    }

    public function test_latest_deployment_returns_null_when_none_recorded(): void
    {
        $site = $this->makeSite();

        $this->assertNull($site->latestDeployment());
    }

    private function makeSite(): Site
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $site->refresh();

        return $site;
    }
}
