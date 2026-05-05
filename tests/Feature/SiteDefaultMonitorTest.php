<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDefaultMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_creation_auto_creates_default_uptime_monitor(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);

        $monitors = SiteUptimeMonitor::query()->where('site_id', $site->id)->get();

        $this->assertCount(1, $monitors);
        $monitor = $monitors->first();
        $this->assertNull($monitor->path);
        $this->assertSame(0, (int) $monitor->sort_order);
        $this->assertNotEmpty($monitor->probe_region);
        $this->assertNotEmpty($monitor->label);
    }
}
