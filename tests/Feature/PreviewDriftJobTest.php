<?php

namespace Tests\Feature;

use App\Jobs\PreviewDriftJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PreviewDriftJobTest extends TestCase
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

    public function test_handle_marks_meta_completed_and_writes_buffer_and_diff_result(): void
    {
        [, $server] = $this->ownerWithServer();
        $runId = '01TESTRUN0000000000000';

        $this->mock(ServerAuthorizedKeysDiffPreview::class, function ($mock): void {
            $mock->shouldReceive('withOutputCallback')
                ->once()
                ->andReturnUsing(function (callable $cb) use ($mock) {
                    $cb('out', '> Comparing 1 target user(s): root');
                    $cb('out', '> Reading authorized_keys for root …');
                    $cb('out', '  root: 1 remote, 1 desired · +0 -0');
                    $cb('out', '> Done.');

                    return $mock;
                });
            $mock->shouldReceive('diffPerUser')
                ->once()
                ->andReturn([
                    'root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []],
                ]);
        });

        $job = new PreviewDriftJob(serverId: $server->id, runId: $runId);
        $job->handle(app(ServerAuthorizedKeysDiffPreview::class));

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('completed', data_get($meta, config('server_ssh_keys.meta_drift_status_key')));
        $this->assertNotEmpty(data_get($meta, config('server_ssh_keys.meta_drift_finished_at_key')));

        $payload = Cache::get($job->cacheKey());
        $this->assertIsArray($payload);
        $this->assertContains('> Done.', $payload['lines']);
        $this->assertContains('> Done. Diff computed.', $payload['lines']);
        $this->assertArrayHasKey('diff_result', $payload);
        $this->assertArrayHasKey('root', $payload['diff_result']);
    }

    public function test_handle_marks_meta_failed_and_records_error_on_throw(): void
    {
        [, $server] = $this->ownerWithServer();
        $runId = '01TESTRUNFAILEDXXXXXXX';

        $this->mock(ServerAuthorizedKeysDiffPreview::class, function ($mock): void {
            $mock->shouldReceive('withOutputCallback')->andReturnSelf();
            $mock->shouldReceive('diffPerUser')
                ->once()
                ->andThrow(new \RuntimeException('Permission denied (publickey).'));
        });

        $job = new PreviewDriftJob(serverId: $server->id, runId: $runId);
        $job->handle(app(ServerAuthorizedKeysDiffPreview::class));

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('failed', data_get($meta, config('server_ssh_keys.meta_drift_status_key')));
        $this->assertSame('Permission denied (publickey).', data_get($meta, config('server_ssh_keys.meta_drift_error_key')));

        $payload = Cache::get($job->cacheKey());
        $this->assertIsArray($payload);
        $errorLines = array_filter($payload['lines'], fn ($l) => str_contains($l, 'ERROR'));
        $this->assertNotEmpty($errorLines);
    }
}
