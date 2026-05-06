<?php

namespace Tests\Feature;

use App\Jobs\SyncAuthorizedKeysJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SyncAuthorizedKeysJobTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Server} */
    private function ownerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        return [$user, $server];
    }

    public function test_handle_marks_meta_completed_and_writes_buffer_on_success(): void
    {
        [$user, $server] = $this->ownerWithServer();
        $runId = '01TESTRUN0000000000000';

        $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock): void {
            // Synchronizer chain: ->withOutputCallback(...)->sync(...). We invoke the captured
            // callback so the job's chunk-write code path actually runs.
            $mock->shouldReceive('withOutputCallback')
                ->once()
                ->andReturnUsing(function (callable $cb) use ($mock) {
                    $cb('out', "Reading current authorized_keys for root\n");
                    $cb('out', "DPLY_AUTH_EXIT:0\n");

                    return $mock;
                });
            $mock->shouldReceive('sync')->once();
        });

        $job = new SyncAuthorizedKeysJob(
            serverId: $server->id,
            runId: $runId,
            userId: $user->id,
            ipAddress: '203.0.113.1',
        );

        $job->handle(app(ServerAuthorizedKeysSynchronizer::class));

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('completed', data_get($meta, config('server_ssh_keys.meta_sync_status_key')));
        $this->assertNotEmpty(data_get($meta, config('server_ssh_keys.meta_sync_finished_at_key')));
        $this->assertNull(data_get($meta, config('server_ssh_keys.meta_sync_error_key')));

        $payload = Cache::get($job->cacheKey());
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['lines']);
        $this->assertContains('Reading current authorized_keys for root', $payload['lines']);
        $this->assertTrue(in_array('> Done. authorized_keys updated successfully.', $payload['lines'], true));
    }

    public function test_handle_marks_meta_failed_and_records_error_on_throw(): void
    {
        [$user, $server] = $this->ownerWithServer();
        $runId = '01TESTRUN0000000000FAIL';

        $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock): void {
            $mock->shouldReceive('withOutputCallback')->andReturnSelf();
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException('Permission denied (publickey).'));
        });

        $job = new SyncAuthorizedKeysJob(
            serverId: $server->id,
            runId: $runId,
            userId: $user->id,
            ipAddress: null,
        );

        $job->handle(app(ServerAuthorizedKeysSynchronizer::class));

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('failed', data_get($meta, config('server_ssh_keys.meta_sync_status_key')));
        $this->assertSame('Permission denied (publickey).', data_get($meta, config('server_ssh_keys.meta_sync_error_key')));

        $payload = Cache::get($job->cacheKey());
        $this->assertIsArray($payload);
        $errorLines = array_filter($payload['lines'], fn ($l) => str_contains($l, 'ERROR'));
        $this->assertNotEmpty($errorLines, 'Streaming buffer should include the error line.');
    }
}
