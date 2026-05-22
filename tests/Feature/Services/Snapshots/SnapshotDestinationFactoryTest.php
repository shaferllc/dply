<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots;

use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Snapshots\LocalDiskDestination;
use App\Services\Snapshots\S3Destination;
use App\Services\Snapshots\SnapshotDestinationFactory;
use Mockery;
use Tests\TestCase;

class SnapshotDestinationFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function factory(): SnapshotDestinationFactory
    {
        return new SnapshotDestinationFactory(Mockery::mock(ExecuteRemoteTaskOnServer::class));
    }

    public function test_local_disk_always_returns_a_destination(): void
    {
        $this->assertInstanceOf(LocalDiskDestination::class, $this->factory()->localDisk());
    }

    public function test_s3_returns_null_when_bucket_not_configured(): void
    {
        config(['snapshot_s3.enabled' => false, 'snapshot_s3.bucket' => null]);

        $this->assertNull($this->factory()->s3());
    }

    public function test_s3_returns_destination_when_bucket_configured(): void
    {
        config([
            'snapshot_s3.enabled' => true,
            'snapshot_s3.bucket' => 'dply-backups',
            'snapshot_s3.region' => 'nyc3',
            'snapshot_s3.endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'snapshot_s3.key' => 'AKIATEST',
            'snapshot_s3.secret' => 'secret',
        ]);

        $this->assertInstanceOf(S3Destination::class, $this->factory()->s3());
    }

    public function test_preferred_falls_back_to_local_when_s3_not_configured(): void
    {
        config(['snapshot_s3.enabled' => false]);

        $this->assertInstanceOf(LocalDiskDestination::class, $this->factory()->preferred());
    }

    public function test_preferred_returns_s3_when_configured(): void
    {
        config([
            'snapshot_s3.enabled' => true,
            'snapshot_s3.bucket' => 'dply-backups',
            'snapshot_s3.region' => 'us-east-1',
        ]);

        $this->assertInstanceOf(S3Destination::class, $this->factory()->preferred());
    }
}
