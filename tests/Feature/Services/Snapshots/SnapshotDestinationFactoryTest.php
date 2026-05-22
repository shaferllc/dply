<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots\SnapshotDestinationFactoryTest;
use Mockery;

use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Snapshots\LocalDiskDestination;
use App\Services\Snapshots\S3Destination;
use App\Services\Snapshots\SnapshotDestinationFactory;
afterEach(function () {
    Mockery::close();
});
function factory(): SnapshotDestinationFactory
{
    return new SnapshotDestinationFactory(Mockery::mock(ExecuteRemoteTaskOnServer::class));
}
test('local disk always returns a destination', function () {
    expect(factory()->localDisk())->toBeInstanceOf(LocalDiskDestination::class);
});
test('s3 returns null when bucket not configured', function () {
    config(['snapshot_s3.enabled' => false, 'snapshot_s3.bucket' => null]);

    expect(factory()->s3())->toBeNull();
});
test('s3 returns destination when bucket configured', function () {
    config([
        'snapshot_s3.enabled' => true,
        'snapshot_s3.bucket' => 'dply-backups',
        'snapshot_s3.region' => 'nyc3',
        'snapshot_s3.endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'snapshot_s3.key' => 'AKIATEST',
        'snapshot_s3.secret' => 'secret',
    ]);

    expect(factory()->s3())->toBeInstanceOf(S3Destination::class);
});
test('preferred falls back to local when s3 not configured', function () {
    config(['snapshot_s3.enabled' => false]);

    expect(factory()->preferred())->toBeInstanceOf(LocalDiskDestination::class);
});
test('preferred returns s3 when configured', function () {
    config([
        'snapshot_s3.enabled' => true,
        'snapshot_s3.bucket' => 'dply-backups',
        'snapshot_s3.region' => 'us-east-1',
    ]);

    expect(factory()->preferred())->toBeInstanceOf(S3Destination::class);
});
