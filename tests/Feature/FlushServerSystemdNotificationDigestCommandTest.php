<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemdNotificationDigestLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FlushServerSystemdNotificationDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_when_no_lines_buffered_for_previous_hour(): void
    {
        $exit = Artisan::call('systemd:flush-notification-digest');

        $this->assertSame(0, $exit);
    }

    public function test_drains_lines_for_previous_hour_bucket(): void
    {
        [$server, $channel, $org] = $this->makeServerAndChannel();
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');

        $line = ServerSystemdNotificationDigestLine::query()->create([
            'notification_channel_id' => $channel->id,
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'digest_bucket' => $bucket,
            'unit' => 'queue.service',
            'event_kind' => 'failed',
            'line' => 'queue.service entered failed state',
        ]);

        Artisan::call('systemd:flush-notification-digest');

        $this->assertNull(ServerSystemdNotificationDigestLine::query()->find($line->id));
    }

    public function test_leaves_current_hour_lines_alone(): void
    {
        [$server, $channel, $org] = $this->makeServerAndChannel();
        $currentBucket = now('UTC')->format('Y-m-d-H');

        $line = ServerSystemdNotificationDigestLine::query()->create([
            'notification_channel_id' => $channel->id,
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'digest_bucket' => $currentBucket,
            'unit' => 'queue.service',
            'event_kind' => 'failed',
            'line' => 'queue.service entered failed state',
        ]);

        Artisan::call('systemd:flush-notification-digest');

        $this->assertNotNull(ServerSystemdNotificationDigestLine::query()->find($line->id));
    }

    public function test_orphaned_lines_with_missing_channel_are_swept(): void
    {
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        // Create the line referencing a deleted channel (simulate by inserting
        // a fake but FK-satisfying channel, then deleting it before the run).
        $channel = NotificationChannel::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);
        $line = ServerSystemdNotificationDigestLine::query()->create([
            'notification_channel_id' => $channel->id,
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'digest_bucket' => $bucket,
            'unit' => 'queue.service',
            'event_kind' => 'failed',
            'line' => 'queue.service entered failed state',
        ]);
        $channel->delete();

        Artisan::call('systemd:flush-notification-digest');

        $this->assertNull(ServerSystemdNotificationDigestLine::query()->find($line->id));
    }

    /**
     * @return array{0: Server, 1: NotificationChannel, 2: Organization}
     */
    private function makeServerAndChannel(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $channel = NotificationChannel::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);

        return [$server, $channel, $org];
    }
}
