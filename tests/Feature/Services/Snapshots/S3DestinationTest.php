<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Snapshots\S3Destination;
use Aws\Command;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class S3DestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'slug' => 'shopco',
        ]);
    }

    /**
     * Build a partial mock of S3Client that returns a fixed presigned
     * URL — the real SDK signing is exercised by AWS's own tests; we
     * just need a deterministic URL to assert curl was called against.
     */
    private function mockS3Client(string $putUrl = 'https://example.com/put', string $getUrl = 'https://example.com/get'): S3Client
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('getCommand')->andReturnUsing(fn ($name, $args) => new Command($name, $args));

        $putRequest = new Request('PUT', $putUrl);
        $getRequest = new Request('GET', $getUrl);

        $s3->shouldReceive('createPresignedRequest')
            ->andReturnUsing(function ($cmd) use ($putRequest, $getRequest) {
                return $cmd->getName() === 'PutObject' ? $putRequest : $getRequest;
            });

        return $s3;
    }

    public function test_persist_streams_dump_to_s3_via_presigned_put_url_and_records_snapshot(): void
    {
        $site = $this->makeSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, string $name, string $bash) {
                $this->assertSame('snapshot:s3-upload', $name);
                $this->assertStringContainsString('curl', $bash);
                $this->assertStringContainsString('--request PUT', $bash);
                $this->assertStringContainsString('--upload-file', $bash);
                $this->assertStringContainsString('https://example.com/put', $bash);
                // Server cleans up its tmp file after upload succeeds.
                $this->assertStringContainsString('rm -f', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput('', 0, false));

        $destination = new S3Destination(
            executor: $executor,
            s3: $this->mockS3Client(),
            bucket: 'dply-backups',
            keyPrefix: 'prod',
        );

        $snapshot = $destination->persist(
            site: $site,
            reason: Snapshot::REASON_MANUAL,
            dumpRemotePath: '/tmp/dply-snapshot-shopco-abc123.sql.gz',
            bytes: 4096,
            engine: 'mysql84',
            userId: $site->user_id,
        );

        $this->assertSame(Snapshot::DESTINATION_S3, $snapshot->destination);
        $this->assertSame('dply-backups', $snapshot->s3_bucket);
        $this->assertStringStartsWith('prod/', $snapshot->s3_key);
        $this->assertStringContainsString($site->id, $snapshot->s3_key);
        $this->assertStringEndsWith('.sql.gz', $snapshot->s3_key);
        $this->assertNull($snapshot->local_path);
        // S3 destinations don't expire — bucket lifecycle owns retention.
        $this->assertNull($snapshot->expires_at);
    }

    public function test_persist_throws_when_curl_upload_fails(): void
    {
        $site = $this->makeSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->andReturn(new ProcessOutput('curl: (22) The requested URL returned error: 403 Forbidden', 22, false));

        $this->expectExceptionMessage('S3 presigned-PUT upload failed');

        try {
            (new S3Destination($executor, $this->mockS3Client(), 'b'))->persist(
                site: $site,
                reason: Snapshot::REASON_MANUAL,
                dumpRemotePath: '/tmp/x.sql.gz',
                bytes: 100,
                engine: 'mysql84',
                userId: null,
            );
        } finally {
            $this->assertSame(0, Snapshot::query()->count(),
                'A failed S3 upload must not leave a Snapshot row behind');
        }
    }

    public function test_restore_streams_via_presigned_get_url_into_engine_client(): void
    {
        $site = $this->makeSite();
        $snapshot = Snapshot::factory()->s3()->create([
            'site_id' => $site->id,
            's3_bucket' => 'dply-backups',
            's3_key' => 'prod/' . $site->organization_id . '/' . $site->id . '/snap.sql.gz',
            'engine' => 'mysql84',
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, string $name, string $bash) {
                $this->assertSame('snapshot:s3-restore', $name);
                $this->assertStringContainsString('curl', $bash);
                $this->assertStringContainsString('https://example.com/get', $bash);
                $this->assertStringContainsString('| gunzip -c | mysql', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput('', 0, false));

        (new S3Destination($executor, $this->mockS3Client(), 'dply-backups'))->restore($snapshot);
    }

    public function test_restore_uses_psql_for_postgres_engines(): void
    {
        $site = $this->makeSite();
        $snapshot = Snapshot::factory()->s3()->create([
            'site_id' => $site->id,
            's3_bucket' => 'b',
            's3_key' => 'k',
            'engine' => 'postgres17',
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBash')
            ->withArgs(function ($s, $name, string $bash) {
                $this->assertStringContainsString('| gunzip -c | psql', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput('', 0, false));

        (new S3Destination($executor, $this->mockS3Client(), 'b'))->restore($snapshot);
    }

    public function test_restore_throws_when_snapshot_has_no_s3_location(): void
    {
        $site = $this->makeSite();
        $snapshot = Snapshot::factory()->create([
            'site_id' => $site->id,
            'destination' => Snapshot::DESTINATION_LOCAL_DISK,
            's3_bucket' => null,
            's3_key' => null,
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBash');

        $this->expectExceptionMessage('no S3 location');
        (new S3Destination($executor, $this->mockS3Client(), 'b'))->restore($snapshot);
    }
}
