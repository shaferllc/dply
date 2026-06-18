<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots\SnapshotServiceTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Modules\Snapshots\Services\LocalDiskDestination;
use App\Modules\Snapshots\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeSite(string $serverEngine = 'mysql84'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['database' => $serverEngine],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'shopco',
        'meta' => ['scaffold' => ['database' => ['name' => 'dply_shopco_custom']]],
    ]);
}
test('take emits mysqldump command for mysql site and writes audit', function () {
    $site = makeSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // 1) The dump itself
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash) {
            expect($name)->toBe('snapshot:dump');
            $this->assertStringContainsString('mysqldump', $bash);
            $this->assertStringContainsString('--single-transaction', $bash);
            $this->assertStringContainsString("'dply_shopco_custom'", $bash);

            return true;
        })
        ->andReturn(new ProcessOutput('', 0, false));

    // 2) Size lookup
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, $n) => $n === 'snapshot:size')
        ->andReturn(new ProcessOutput("4096\n", 0, false));

    // 3) LocalDiskDestination's mv into the per-site dir
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, $n) => $n === 'snapshot:local-stash')
        ->andReturn(new ProcessOutput('', 0, false));

    $service = new SnapshotService($executor, app(SiteAuditWriter::class));
    $destination = new LocalDiskDestination($executor);

    $snapshot = $service->take(
        site: $site,
        destination: $destination,
        reason: Snapshot::REASON_MANUAL,
        userId: $site->user_id,
    );

    expect($snapshot->destination)->toBe(Snapshot::DESTINATION_LOCAL_DISK);
    expect($snapshot->bytes)->toBe(4096);
    expect($snapshot->engine)->toBe('mysql84');
    expect($snapshot->reason)->toBe(Snapshot::REASON_MANUAL);
    expect($snapshot->expires_at)->not->toBeNull();
    $this->assertStringContainsString('/home/dply/snapshots/shopco/', $snapshot->local_path);

    $event = SiteAuditEvent::query()->where('action', 'snapshot_taken')->sole();
    expect($event->payload['snapshot_id'])->toBe($snapshot->id);
});
test('take emits pg dump command for postgres site', function () {
    $site = makeSite(serverEngine: 'postgres17');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash) {
            if ($name !== 'snapshot:dump') {
                return false;
            }
            $this->assertStringContainsString('pg_dump', $bash);
            $this->assertStringContainsString('--no-owner', $bash);

            return true;
        })
        ->andReturn(new ProcessOutput('', 0, false));
    $executor->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput("100\n", 0, false));

    $snapshot = (new SnapshotService($executor, app(SiteAuditWriter::class)))->take(
        site: $site,
        destination: new LocalDiskDestination($executor),
        reason: Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
        userId: null,
    );

    expect($snapshot->engine)->toBe('postgres17');
    expect($snapshot->reason)->toBe(Snapshot::REASON_PRE_MIGRATION_ROLLBACK);
});
test('take throws and audits when dump returns nonzero', function () {
    $site = makeSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, $n) => $n === 'snapshot:dump')
        ->andReturn(new ProcessOutput('access denied', 2, false));

    $this->expectExceptionMessage('exited 2');

    try {
        (new SnapshotService($executor, app(SiteAuditWriter::class)))->take(
            site: $site,
            destination: new LocalDiskDestination($executor),
            reason: Snapshot::REASON_MANUAL,
        );
    } finally {
        // The pending row is created up front and flipped to failed (not deleted)
        // so the operator can see what went wrong in the Databases history.
        $snap = Snapshot::query()->sole();
        expect($snap->status)->toBe(Snapshot::STATUS_FAILED);
        $this->assertStringContainsString('exited 2', (string) $snap->error_message);
    }
});
test('restore runs gunzip pipe and audits destructive action', function () {
    $site = makeSite();
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    $snapshot = Snapshot::factory()->create([
        'site_id' => $site->id,
        'destination' => Snapshot::DESTINATION_LOCAL_DISK,
        'local_path' => '/home/dply/snapshots/shopco/abcd.sql.gz',
        'engine' => 'mysql84',
    ]);

    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(function ($s, string $name, string $bash) {
            expect($name)->toBe('snapshot:local-restore');
            $this->assertStringContainsString('gunzip', $bash);
            $this->assertStringContainsString('| mysql', $bash);

            return true;
        })
        ->andReturn(new ProcessOutput('', 0, false));

    $service = new SnapshotService($executor, app(SiteAuditWriter::class));
    $service->restore($snapshot, new LocalDiskDestination($executor), userId: $site->user_id);

    $event = SiteAuditEvent::query()->where('action', 'snapshot_restored')->sole();
    expect($event->payload['snapshot_id'])->toBe($snapshot->id);
    expect($event->risk->value)->toBe('destructive');
});
