<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots\S3DestinationTest;

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

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeSite(): Site
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
function mockS3Client(string $putUrl = 'https://example.com/put', string $getUrl = 'https://example.com/get'): S3Client
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
test('persist streams dump to s3 via presigned put url and records snapshot', function () {
    $site = makeSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash) {
            expect($name)->toBe('snapshot:s3-upload');
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
        s3: mockS3Client(),
        bucket: 'dply-backups',
        keyPrefix: 'prod',
    );

    // The pending row is created up front by SnapshotService::take(); persist()
    // fills it in and flips it to completed.
    $snapshot = Snapshot::create([
        'site_id' => $site->id,
        'destination' => Snapshot::DESTINATION_S3,
        'engine' => 'mysql84',
        'reason' => Snapshot::REASON_MANUAL,
        'taken_by_user_id' => $site->user_id,
        'status' => Snapshot::STATUS_PENDING,
    ]);

    $destination->persist(
        snapshot: $snapshot,
        dumpRemotePath: '/tmp/dply-snapshot-shopco-abc123.sql.gz',
        bytes: 4096,
    );

    $snapshot->refresh();
    expect($snapshot->status)->toBe(Snapshot::STATUS_COMPLETED);
    expect($snapshot->destination)->toBe(Snapshot::DESTINATION_S3);
    expect($snapshot->s3_bucket)->toBe('dply-backups');
    expect($snapshot->s3_key)->toStartWith('prod/');
    $this->assertStringContainsString($site->id, $snapshot->s3_key);
    expect($snapshot->s3_key)->toEndWith('.sql.gz');
    expect($snapshot->local_path)->toBeNull();

    // S3 destinations don't expire — bucket lifecycle owns retention.
    expect($snapshot->expires_at)->toBeNull();
});
test('persist throws when curl upload fails', function () {
    $site = makeSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput('curl: (22) The requested URL returned error: 403 Forbidden', 22, false));

    $pending = Snapshot::create([
        'site_id' => $site->id,
        'destination' => Snapshot::DESTINATION_S3,
        'engine' => 'mysql84',
        'reason' => Snapshot::REASON_MANUAL,
        'status' => Snapshot::STATUS_PENDING,
    ]);

    $this->expectExceptionMessage('S3 presigned-PUT upload failed');

    try {
        (new S3Destination($executor, mockS3Client(), 'b'))->persist(
            snapshot: $pending,
            dumpRemotePath: '/tmp/x.sql.gz',
            bytes: 100,
        );
    } finally {
        // persist() never flips a failed upload to completed; the row stays
        // pending. SnapshotService::take() is what marks it failed.
        expect($pending->fresh()->status)->toBe(Snapshot::STATUS_PENDING);
    }
});
test('restore streams via presigned get url into engine client', function () {
    $site = makeSite();
    $snapshot = Snapshot::factory()->s3()->create([
        'site_id' => $site->id,
        's3_bucket' => 'dply-backups',
        's3_key' => 'prod/'.$site->organization_id.'/'.$site->id.'/snap.sql.gz',
        'engine' => 'mysql84',
    ]);

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash) {
            expect($name)->toBe('snapshot:s3-restore');
            $this->assertStringContainsString('curl', $bash);
            $this->assertStringContainsString('https://example.com/get', $bash);
            $this->assertStringContainsString('| gunzip -c | mysql', $bash);

            return true;
        })
        ->andReturn(new ProcessOutput('', 0, false));

    (new S3Destination($executor, mockS3Client(), 'dply-backups'))->restore($snapshot);
});
test('restore uses psql for postgres engines', function () {
    $site = makeSite();
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

    (new S3Destination($executor, mockS3Client(), 'b'))->restore($snapshot);
});
test('restore throws when snapshot has no s3 location', function () {
    $site = makeSite();
    $snapshot = Snapshot::factory()->create([
        'site_id' => $site->id,
        'destination' => Snapshot::DESTINATION_LOCAL_DISK,
        's3_bucket' => null,
        's3_key' => null,
    ]);

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBash');

    $this->expectExceptionMessage('no S3 location');
    (new S3Destination($executor, mockS3Client(), 'b'))->restore($snapshot);
});
