<?php

namespace Tests\Feature;

use App\Livewire\Sites\Monitor as SitesMonitor;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteUptimeMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrg(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_authenticated_user_can_add_uptime_monitor(): void
    {
        Queue::fake();

        $user = $this->userWithOrg();
        $org = $user->currentOrganization();

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.test',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(SitesMonitor::class, ['server' => $server, 'site' => $site])
            ->set('newLabel', 'Homepage')
            ->set('newPath', '/api/health')
            ->set('newProbeRegion', 'eu-amsterdam')
            ->call('addMonitor');

        $this->assertDatabaseHas('site_uptime_monitors', [
            'site_id' => $site->id,
            'label' => 'Homepage',
            'path' => '/api/health',
            'probe_region' => 'eu-amsterdam',
        ]);
    }

    public function test_monitor_route_requires_auth(): void
    {
        $user = $this->userWithOrg();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $this->get(route('sites.monitor', [$server, $site]))->assertRedirect();

        $this->actingAs($user)->get(route('sites.monitor', [$server, $site]))->assertOk();
    }
}
