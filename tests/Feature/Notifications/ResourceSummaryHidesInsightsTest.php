<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\ResourceSummaryHidesInsightsTest;

use App\Livewire\Notifications\ResourceSummary;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\Insights\Services\InsightsNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function actor(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    return [$user, $org, $server];
}
function makeEvent(Server $server, Organization $org, string $eventKey, string $title): NotificationEvent
{
    return NotificationEvent::query()->create([
        'event_key' => $eventKey,
        'subject_type' => null,
        'subject_id' => null,
        'resource_type' => Server::class,
        'resource_id' => $server->id,
        'organization_id' => $org->id,
        'team_id' => null,
        'actor_id' => null,
        'title' => $title,
        'body' => 'fixture body',
        'url' => null,
        'severity' => 'info',
        'category' => 'server',
        'supports_in_app' => true,
        'supports_email' => true,
        'supports_webhook' => true,
        'metadata' => [],
        'occurred_at' => now(),
    ]);
}
test('widget hides insight event rows but shows other server events', function () {
    [$user, $org, $server] = actor();

    $insight = makeEvent($server, $org, InsightsNotificationDispatcher::EVENT_KEY, 'oceanic-grove — 96 security updates');
    $monitoring = makeEvent($server, $org, 'server.monitoring', 'CPU at 92%');

    $component = Livewire::actingAs($user)
        ->test(ResourceSummary::class, ['resource' => $server]);

    $component->assertSee('CPU at 92%');
    $component->assertDontSee('oceanic-grove');

    // Insight row is still in the DB — channel subscribers (Slack/email) keep getting it.
    $this->assertDatabaseHas('notification_events', [
        'id' => $insight->id,
        'cleared_at' => null,
    ]);
    expect($monitoring->fresh())->not->toBeNull();
});
test('clear all does not sweep insight event rows', function () {
    [$user, $org, $server] = actor();

    $insight = makeEvent($server, $org, InsightsNotificationDispatcher::EVENT_KEY, 'security updates');
    $monitoring = makeEvent($server, $org, 'server.monitoring', 'cpu spike');

    Livewire::actingAs($user)
        ->test(ResourceSummary::class, ['resource' => $server])
        ->call('clearAll');

    expect($monitoring->fresh()->cleared_at)->not->toBeNull('Visible monitoring row should be cleared.');
    expect($insight->fresh()->cleared_at)->toBeNull('Hidden insight row must not be cleared on the user behalf.');
});
