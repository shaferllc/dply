<?php


namespace Tests\Unit\Events\ServerCronRunBroadcastTest;
use App\Events\Servers\ServerCronRunCompletedBroadcast;
use App\Events\Servers\ServerCronRunMetaBroadcast;
use App\Events\Servers\ServerCronRunOutputChunkBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\Attributes\Test;

test('meta broadcasts on private server channel', function () {
    $e = new ServerCronRunMetaBroadcast('srv1', 'run1', '<p>x</p>');
    $channels = $e->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($e->broadcastAs())->toBe('server.cron.run.meta');
});

test('chunk broadcast name', function () {
    $e = new ServerCronRunOutputChunkBroadcast('srv1', 'run1', 'out');
    expect($e->broadcastAs())->toBe('server.cron.run.chunk');
});

test('completed broadcast name', function () {
    $e = new ServerCronRunCompletedBroadcast('srv1', 'run1', true, null, 'ok', 'full');
    expect($e->broadcastAs())->toBe('server.cron.run.completed');
    expect($e->broadcastWith())->toHaveKey('final_output');
});
