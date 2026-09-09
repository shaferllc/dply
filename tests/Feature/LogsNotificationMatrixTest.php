<?php

namespace Tests\Feature\LogsNotificationMatrixTest;

use App\Livewire\Servers\WorkspaceLogs;
use App\Livewire\Sites\Logs;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\ServerLogNotificationKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function logsMatrixFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Ops inbox',
        'config' => ['email' => 'ops@example.com'],
    ]);

    return [$user, $server, $channel];
}

test('the server logs notifications tab saves subscriptions through the shared matrix', function () {
    [$user, $server, $channel] = logsMatrixFixture();
    $key = ServerLogNotificationKeys::eventKeys()[0];

    Livewire::actingAs($user)
        ->test(WorkspaceLogs::class, ['server' => $server])
        ->set('channelEventSelections', [(string) $channel->id => [$key]])
        ->call('saveFeatureNotificationSubscriptions')
        ->assertHasNoErrors();

    expect(NotificationSubscription::query()
        ->where('subscribable_type', Server::class)
        ->where('subscribable_id', $server->id)
        ->where('notification_channel_id', $channel->id)
        ->where('event_key', $key)
        ->exists())->toBeTrue();
});

test('the site logs notifications tab saves the same server-scoped subscriptions', function () {
    [$user, $server, $channel] = logsMatrixFixture();
    $key = ServerLogNotificationKeys::eventKeys()[0];

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
    ]);

    Livewire::actingAs($user)
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->set('channelEventSelections', [(string) $channel->id => [$key]])
        ->call('saveFeatureNotificationSubscriptions')
        ->assertHasNoErrors();

    expect(NotificationSubscription::query()
        ->where('subscribable_type', Server::class)
        ->where('subscribable_id', $server->id)
        ->where('notification_channel_id', $channel->id)
        ->where('event_key', $key)
        ->exists())->toBeTrue();
});

test('saving logs leaves another feature\'s subscriptions untouched', function () {
    [$user, $server, $channel] = logsMatrixFixture();

    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => 'server.backup.failed',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceLogs::class, ['server' => $server])
        ->set('channelEventSelections', [(string) $channel->id => [ServerLogNotificationKeys::eventKeys()[0]]])
        ->call('saveFeatureNotificationSubscriptions');

    expect(NotificationSubscription::query()
        ->where('subscribable_id', $server->id)
        ->where('event_key', 'server.backup.failed')
        ->exists())->toBeTrue();
});

test('every log event key carries a label', function () {
    [$user, $server] = logsMatrixFixture();

    $component = Livewire::actingAs($user)->test(WorkspaceLogs::class, ['server' => $server]);

    expect($component->instance()->unlabelledFeatureEventKeys())->toBe([]);
});
