<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Snapshots;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\Snapshot;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Snapshots\LocalDiskDestination;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSite(string $serverEngine = 'mysql84'): Site
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

    public function test_take_emits_mysqldump_command_for_mysql_site_and_writes_audit(): void
    {
        $site = $this->makeSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        // 1) The dump itself
        $executor->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(function ($s, string $name, string $bash) {
                $this->assertSame('snapshot:dump', $name);
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

        $this->assertSame(Snapshot::DESTINATION_LOCAL_DISK, $snapshot->destination);
        $this->assertSame(4096, $snapshot->bytes);
        $this->assertSame('mysql84', $snapshot->engine);
        $this->assertSame(Snapshot::REASON_MANUAL, $snapshot->reason);
        $this->assertNotNull($snapshot->expires_at);
        $this->assertStringContainsString('/home/dply/snapshots/shopco/', $snapshot->local_path);

        $event = SiteAuditEvent::query()->where('action', 'snapshot_taken')->sole();
        $this->assertSame($snapshot->id, $event->payload['snapshot_id']);
    }

    public function test_take_emits_pg_dump_command_for_postgres_site(): void
    {
        $site = $this->makeSite(serverEngine: 'postgres17');

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

        $this->assertSame('postgres17', $snapshot->engine);
        $this->assertSame(Snapshot::REASON_PRE_MIGRATION_ROLLBACK, $snapshot->reason);
    }

    public function test_take_throws_and_audits_when_dump_returns_nonzero(): void
    {
        $site = $this->makeSite();

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
            $this->assertSame(0, Snapshot::query()->count(),
                'Failed dump must not leave a Snapshot row behind');
        }
    }

    public function test_restore_runs_gunzip_pipe_and_audits_destructive_action(): void
    {
        $site = $this->makeSite();
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
                $this->assertSame('snapshot:local-restore', $name);
                $this->assertStringContainsString('gunzip', $bash);
                $this->assertStringContainsString('| mysql', $bash);

                return true;
            })
            ->andReturn(new ProcessOutput('', 0, false));

        $service = new SnapshotService($executor, app(SiteAuditWriter::class));
        $service->restore($snapshot, new LocalDiskDestination($executor), userId: $site->user_id);

        $event = SiteAuditEvent::query()->where('action', 'snapshot_restored')->sole();
        $this->assertSame($snapshot->id, $event->payload['snapshot_id']);
        $this->assertSame('destructive', $event->risk->value);
    }
}
