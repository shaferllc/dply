<?php

namespace Tests\Feature\Livewire\Sites\NotificationsCliPanelTest;

use App\Livewire\Sites\Settings;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The section renders a coming-soon teaser until the surface flag is on.
usesFeatures('workspace.site_notifications');

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function notificationsPageFixture(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        // A provisioning site renders the setup screen instead of any section.
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}

it('lists the notification commands with a real channel id', function () {
    [$user, $server, $site] = notificationsPageFixture();
    $channel = NotificationChannel::factory()->forUser($user)->create(['label' => 'Ops Slack']);

    Livewire::withoutLazyLoading();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
        ->assertSee("dply sites:notifications {$site->slug}")
        ->assertSee('dply notifications channels')
        ->assertSee('dply notifications events --subject site')
        ->assertSee("dply notifications subscribe site.uptime.down --channel {$channel->id} --site {$site->slug}")
        ->assertSee("dply notifications unsubscribe site.uptime.down --channel {$channel->id} --site {$site->slug}")
        ->assertSee("dply notifications test {$channel->id}")
        ->assertSee("dply notifications --server {$server->id}");
});

it('falls back to a placeholder when no channel exists yet', function () {
    [$user, $server, $site] = notificationsPageFixture();

    Livewire::withoutLazyLoading();

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
        ->assertSee('--channel &lt;channel&gt;', false);
});
