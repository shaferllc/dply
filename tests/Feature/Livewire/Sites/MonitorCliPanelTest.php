<?php

namespace Tests\Feature\Livewire\Sites\MonitorCliPanelTest;

use App\Livewire\Sites\Monitor;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function monitorPageFixture(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    return [$user, $server, $site];
}

it('lists every uptime command for the site, with a real monitor id', function () {
    [$user, $server, $site] = monitorPageFixture();

    SiteUptimeMonitor::query()->create([
        'site_id' => $site->id,
        'label' => 'Homepage',
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
        'path' => '/',
        'probe_region' => 'eu-amsterdam',
        'sort_order' => 0,
    ]);

    $page = Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);

    // The page seeds default monitors on mount, so the id the panel shows is
    // whichever sorts first — read it back rather than assuming it is ours.
    $shown = $site->fresh()->uptimeMonitors()->orderBy('sort_order')->first();
    expect($shown)->not->toBeNull();

    $page
        ->assertSee("dply sites:uptime {$site->slug}")
        ->assertSee("dply sites:uptime:history {$site->slug}")
        ->assertSee("dply uptime history {$site->slug} --monitor {$shown->id}")
        ->assertSee("dply uptime check {$shown->id} --site {$site->slug}")
        ->assertSee("dply uptime check --all --site {$site->slug}")
        ->assertSee("dply uptime {$site->slug} --watch")
        ->assertSee("dply sites:errors {$site->slug}")
        ->assertDontSee('dply serverless status');
});

it('adds the function rollup for a serverless site', function () {
    [$user, $server, $site] = monitorPageFixture();
    $site->meta = ['runtime_profile' => 'digitalocean_functions_web'];
    $site->save();

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site->fresh()])
        ->assertSee("dply serverless status {$site->slug}");
});

it('spells out the routing commands on the alerts sub-tab', function () {
    [$user, $server, $site] = monitorPageFixture();
    $channel = NotificationChannel::factory()->forUser($user)->create(['label' => 'Pager']);

    Livewire::actingAs($user)
        ->test(Monitor::class, ['server' => $server, 'site' => $site])
        ->call('setMonitorWorkspaceTab', 'alerts')
        ->assertSee("dply notifications subscribe site.uptime.down site.uptime.degraded site.ssl.expiring --channel {$channel->id} --site {$site->slug}")
        ->assertSee("dply sites:uptime {$site->slug}");
});
