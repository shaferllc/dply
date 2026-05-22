<?php


namespace Tests\Unit\Events\ServerWorkspaceLogSnapshotBroadcastTest;
use App\Events\Servers\ServerWorkspaceLogSnapshotBroadcast;
use PHPUnit\Framework\Attributes\Test;

it('broadcasts on private server channel', function () {
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
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-server.01HZABCDEF0123456789012345');
    expect($event->broadcastAs())->toBe('server.workspace.log.snapshot');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKey('site_id');
    expect($payload['site_id'])->toBeNull();
});

it('includes site id in broadcast payload when set', function () {
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

    expect($event->broadcastWith()['site_id'])->toBe('01HZABCDEF0123456789012345');
});
