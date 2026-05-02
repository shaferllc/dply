<?php

namespace Tests\Unit\Events;

use App\Events\Servers\ServerWorkspaceLogSnapshotBroadcast;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerWorkspaceLogSnapshotBroadcastTest extends TestCase
{
    #[Test]
    public function it_broadcasts_on_private_server_channel(): void
    {
        $event = new ServerWorkspaceLogSnapshotBroadcast(
            serverId: '01HZABCDEF0123456789012345',
            logKey: 'nginx_error',
            remoteLogRaw: 'log line',
            remoteLogError: null,
            logLastFetchedAt: '2026-03-30T12:00:00+00:00',
            logLastFetchTruncated: false,
            logLastFetchRawBytes: 8,
            broadcastPayloadTruncated: false,
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-server.01HZABCDEF0123456789012345', $channels[0]->name);
        $this->assertSame('server.workspace.log.snapshot', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertArrayHasKey('site_id', $payload);
        $this->assertNull($payload['site_id']);
    }

    #[Test]
    public function it_includes_site_id_in_broadcast_payload_when_set(): void
    {
        $event = new ServerWorkspaceLogSnapshotBroadcast(
            serverId: '01HZABCDEF0123456789012345',
            logKey: 'site_01HZABCDEF0123456789012345_platform',
            remoteLogRaw: 'log line',
            remoteLogError: null,
            logLastFetchedAt: '2026-03-30T12:00:00+00:00',
            logLastFetchTruncated: false,
            logLastFetchRawBytes: 8,
            broadcastPayloadTruncated: false,
            siteId: '01HZABCDEF0123456789012345',
        );

        $this->assertSame('01HZABCDEF0123456789012345', $event->broadcastWith()['site_id']);
    }
}
