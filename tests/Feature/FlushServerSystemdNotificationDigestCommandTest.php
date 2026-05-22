<?php

declare(strict_types=1);

namespace Tests\Feature\FlushServerSystemdNotificationDigestCommandTest;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemdNotificationDigestLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('no op when no lines buffered for previous hour', function () {
    $exit = Artisan::call('systemd:flush-notification-digest');

    expect($exit)->toBe(0);
});
test('drains lines for previous hour bucket', function () {
    [$server, $channel, $org] = makeServerAndChannel();
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

    expect(ServerSystemdNotificationDigestLine::query()->find($line->id))->toBeNull();
});
test('leaves current hour lines alone', function () {
    [$server, $channel, $org] = makeServerAndChannel();
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

    expect(ServerSystemdNotificationDigestLine::query()->find($line->id))->not->toBeNull();
});
test('orphaned lines with missing channel are swept', function () {
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

    expect(ServerSystemdNotificationDigestLine::query()->find($line->id))->toBeNull();
});
/**
 * @return array{0: Server, 1: NotificationChannel, 2: Organization}
 */
function makeServerAndChannel(): array
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
