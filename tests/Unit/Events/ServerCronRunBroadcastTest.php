<?php

namespace Tests\Unit\Events;

use App\Events\Servers\ServerCronRunCompletedBroadcast;
use App\Events\Servers\ServerCronRunMetaBroadcast;
use App\Events\Servers\ServerCronRunOutputChunkBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerCronRunBroadcastTest extends TestCase
{
    #[Test]
    public function meta_broadcasts_on_private_server_channel(): void
    {
        $e = new ServerCronRunMetaBroadcast('srv1', 'run1', '<p>x</p>');
        $channels = $e->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('server.cron.run.meta', $e->broadcastAs());
    }

    #[Test]
    public function chunk_broadcast_name(): void
    {
        $e = new ServerCronRunOutputChunkBroadcast('srv1', 'run1', 'out');
        $this->assertSame('server.cron.run.chunk', $e->broadcastAs());
    }

    #[Test]
    public function completed_broadcast_name(): void
    {
        $e = new ServerCronRunCompletedBroadcast('srv1', 'run1', true, null, 'ok', 'full');
        $this->assertSame('server.cron.run.completed', $e->broadcastAs());
        $this->assertArrayHasKey('final_output', $e->broadcastWith());
    }
}
