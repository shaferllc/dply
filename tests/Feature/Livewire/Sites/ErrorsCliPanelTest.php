<?php

namespace Tests\Feature\Livewire\Sites\ErrorsCliPanelTest;

use App\Livewire\Sites\Errors;
use App\Models\ErrorEvent;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function errorsPageFixture(array $siteAttributes = []): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ], $siteAttributes));

    return [$user, $server, $site];
}

it('lists every errors command for the site, with a real event id', function () {
    [$user, $server, $site] = errorsPageFixture();

    $event = ErrorEvent::create([
        'organization_id' => $site->organization_id,
        'server_id' => $server->id,
        'site_id' => $site->id,
        'source_type' => $site->getMorphClass(),
        'source_id' => (string) Str::ulid(),
        'category' => 'deploy',
        'title' => 'Deploy failed',
        'occurred_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->assertSee("dply sites:errors {$site->slug}")
        ->assertSee("dply sites:errors {$site->slug} --full")
        ->assertSee("dply sites:errors {$site->slug} --watch")
        ->assertSee("dply errors dismiss {$event->id} --site {$site->slug}")
        ->assertSee("dply errors dismiss --all --site {$site->slug}")
        ->assertSee("dply errors retry {$event->id} --site {$site->slug}")
        ->assertSee("dply errors fix {$event->id} --site {$site->slug}")
        // A VM site has no invocations to drill into.
        ->assertDontSee('dply serverless errors');
});

it('falls back to a placeholder id when the stream is empty', function () {
    [$user, $server, $site] = errorsPageFixture();

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->assertSee("dply errors dismiss &lt;id&gt; --site {$site->slug}", false);
});

it('spells out the routing commands on the notifications sub-tab', function () {
    [$user, $server, $site] = errorsPageFixture();
    $channel = NotificationChannel::factory()->forUser($user)->create(['label' => 'Ops Slack']);

    Livewire::actingAs($user)
        ->test(Errors::class, ['server' => $server, 'site' => $site])
        ->call('setErrorsWorkspaceTab', 'notifications')
        ->assertSee("dply notifications subscribe site.errors.deploy_failed site.errors.operation_failed --channel {$channel->id} --site {$site->slug}")
        ->assertSee("dply notifications test {$channel->id}");
});
