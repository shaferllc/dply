<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DetectWebserverHealthTransitionsJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Notifications\WebserverHealthAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Edge-trigger behavior of the alert-detection job. Two scenarios matter:
 *   - Tripping the threshold should send ONE notification (not one per
 *     subsequent scrape while still tripped).
 *   - Recovery should send another notification on the down→up transition.
 */
class DetectWebserverHealthTransitionsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);

        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }

    private function snapshot(Server $server, array $webserverHealth): void
    {
        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_pct' => 5.0,
                'webserver_health' => $webserverHealth,
            ],
        ]);
    }

    public function test_fires_alert_on_threshold_trip(): void
    {
        Notification::fake();
        $server = $this->makeServer();
        // Default config threshold for active_connections is gt 5000.
        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);

        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        Notification::assertSentTimes(WebserverHealthAlertNotification::class, 1);
    }

    public function test_no_alert_when_within_threshold(): void
    {
        Notification::fake();
        $server = $this->makeServer();
        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 10]]);

        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_no_repeat_alert_while_still_tripped(): void
    {
        Notification::fake();
        $server = $this->makeServer();

        // First scrape — trips.
        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);
        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        // Second scrape — still tripped. Should NOT re-fire.
        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 7000]]);
        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        Notification::assertSentTimes(WebserverHealthAlertNotification::class, 1);
    }

    public function test_fires_recovery_notification_on_down_transition(): void
    {
        Notification::fake();
        $server = $this->makeServer();

        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 6000]]);
        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        // Drop below threshold — should fire RECOVERY notification.
        $this->snapshot($server, [['engine' => 'nginx', 'active_connections' => 100]]);
        app()->call([new DetectWebserverHealthTransitionsJob($server->id), 'handle']);

        Notification::assertSentTimes(WebserverHealthAlertNotification::class, 2);
    }
}
