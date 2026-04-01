<?php

namespace Tests\Feature;

use App\Models\InsightFinding;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Insights\InsightsNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InsightsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_dispatcher_publishes_universal_event_and_sends_subscribed_channels(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $owner = User::factory()->create();
        $org = Organization::factory()->create([
            'insights_preferences' => [
                'digest_non_critical' => false,
                'quiet_hours_enabled' => false,
                'quiet_hours_start' => 22,
                'quiet_hours_end' => 7,
            ],
        ]);
        $org->users()->attach($owner->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'name' => 'prod-app',
        ]);

        $finding = InsightFinding::query()->create([
            'server_id' => $server->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => 'metrics_missing_or_stale',
            'dedupe_hash' => 'finding-1',
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_WARNING,
            'title' => 'Metrics stopped arriving',
            'body' => 'No fresh samples have been received for 12 minutes.',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);

        $channel = NotificationChannel::factory()->forUser($owner)->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Ops',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
            ],
        ]);

        NotificationSubscription::query()->create([
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => InsightsNotificationDispatcher::EVENT_KEY,
        ]);

        app(InsightsNotificationDispatcher::class)->notifyIfSubscribed($server, $finding, false);

        $this->assertDatabaseHas('notification_events', [
            'event_key' => InsightsNotificationDispatcher::EVENT_KEY,
            'subject_type' => InsightFinding::class,
            'subject_id' => $finding->id,
            'organization_id' => $org->id,
        ]);

        $this->assertDatabaseHas('notification_inbox_items', [
            'user_id' => $owner->id,
            'title' => '['.config('app.name').'] [WARNING] prod-app — Metrics stopped arriving',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }
}
