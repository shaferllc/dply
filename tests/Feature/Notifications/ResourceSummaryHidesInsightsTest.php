<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Livewire\Notifications\ResourceSummary;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Insights\InsightsNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResourceSummaryHidesInsightsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Server}
     */
    private function actor(): array
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

    private function makeEvent(Server $server, Organization $org, string $eventKey, string $title): NotificationEvent
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

    public function test_widget_hides_insight_event_rows_but_shows_other_server_events(): void
    {
        [$user, $org, $server] = $this->actor();

        $insight = $this->makeEvent($server, $org, InsightsNotificationDispatcher::EVENT_KEY, 'oceanic-grove — 96 security updates');
        $monitoring = $this->makeEvent($server, $org, 'server.monitoring', 'CPU at 92%');

        $component = Livewire::actingAs($user)
            ->test(ResourceSummary::class, ['resource' => $server]);

        $component->assertSee('CPU at 92%');
        $component->assertDontSee('oceanic-grove');
        // Insight row is still in the DB — channel subscribers (Slack/email) keep getting it.
        $this->assertDatabaseHas('notification_events', [
            'id' => $insight->id,
            'cleared_at' => null,
        ]);
        $this->assertNotNull($monitoring->fresh());
    }

    public function test_clear_all_does_not_sweep_insight_event_rows(): void
    {
        [$user, $org, $server] = $this->actor();

        $insight = $this->makeEvent($server, $org, InsightsNotificationDispatcher::EVENT_KEY, 'security updates');
        $monitoring = $this->makeEvent($server, $org, 'server.monitoring', 'cpu spike');

        Livewire::actingAs($user)
            ->test(ResourceSummary::class, ['resource' => $server])
            ->call('clearAll');

        $this->assertNotNull($monitoring->fresh()->cleared_at, 'Visible monitoring row should be cleared.');
        $this->assertNull($insight->fresh()->cleared_at, 'Hidden insight row must not be cleared on the user behalf.');
    }
}
