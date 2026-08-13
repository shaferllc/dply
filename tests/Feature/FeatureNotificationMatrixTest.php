<?php

namespace Tests\Feature\FeatureNotificationMatrixTest;

use App\Livewire\Servers\WorkspaceHealth;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerHealthNotificationKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function matrixFixture(): array
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

test('the health tab saves subscriptions through the shared matrix', function () {
    [$user, $server, $channel] = matrixFixture();
    $key = ServerHealthNotificationKeys::eventKeys()[0];

    Livewire::actingAs($user)
        ->test(WorkspaceHealth::class, ['server' => $server])
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

test('unticking removes only that event', function () {
    [$user, $server, $channel] = matrixFixture();
    [$first, $second] = ServerHealthNotificationKeys::eventKeys();

    $component = Livewire::actingAs($user)->test(WorkspaceHealth::class, ['server' => $server]);

    $component->set('channelEventSelections', [(string) $channel->id => [$first, $second]])
        ->call('saveFeatureNotificationSubscriptions');

    $component->set('channelEventSelections', [(string) $channel->id => [$second]])
        ->call('saveFeatureNotificationSubscriptions');

    $remaining = NotificationSubscription::query()
        ->where('subscribable_id', $server->id)
        ->where('notification_channel_id', $channel->id)
        ->pluck('event_key')
        ->all();

    expect($remaining)->toBe([$second]);
});

test('saving health leaves another feature\'s subscriptions untouched', function () {
    [$user, $server, $channel] = matrixFixture();

    // A backup subscription on the same channel and the same server. The health
    // tab must never reconcile it away — this is the property that makes twenty
    // narrow matrices safe against one table.
    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => 'server.backup.failed',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->set('channelEventSelections', [(string) $channel->id => [ServerHealthNotificationKeys::eventKeys()[0]]])
        ->call('saveFeatureNotificationSubscriptions');

    expect(NotificationSubscription::query()
        ->where('subscribable_id', $server->id)
        ->where('event_key', 'server.backup.failed')
        ->exists())->toBeTrue();
});

test('the tab seeds existing subscriptions into the matrix on mount', function () {
    [$user, $server, $channel] = matrixFixture();
    $key = ServerHealthNotificationKeys::eventKeys()[0];

    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => $key,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->assertSet('channelEventSelections', [(string) $channel->id => [$key]]);
});

test('every health event key carries a label', function () {
    [$user, $server] = matrixFixture();

    // A routed-but-unlabelled key would render as a raw slug in the matrix.
    $component = Livewire::actingAs($user)->test(WorkspaceHealth::class, ['server' => $server]);

    expect($component->instance()->unlabelledFeatureEventKeys())->toBe([]);
});
