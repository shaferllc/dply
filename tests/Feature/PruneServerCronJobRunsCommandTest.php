<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerCronJobRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneServerCronJobRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_runs_older_than_retention(): void
    {
        Config::set('cron_workspace.run_retention_days', 30);

        $job = $this->makeCronJob();
        $stale = $this->makeRun($job, now()->subDays(45));
        $recent = $this->makeRun($job, now()->subDays(10));

        $exit = Artisan::call('dply:prune-cron-job-runs');

        $this->assertSame(0, $exit);
        $this->assertNull(ServerCronJobRun::query()->find($stale->id));
        $this->assertNotNull(ServerCronJobRun::query()->find($recent->id));
    }

    public function test_minimum_retention_is_seven_days(): void
    {
        Config::set('cron_workspace.run_retention_days', 1);

        $job = $this->makeCronJob();
        $threeDaysOld = $this->makeRun($job, now()->subDays(3));
        $tenDaysOld = $this->makeRun($job, now()->subDays(10));

        Artisan::call('dply:prune-cron-job-runs');

        $this->assertNotNull(
            ServerCronJobRun::query()->find($threeDaysOld->id),
            '3-day-old run should survive even when config says 1-day retention (floored to 7)',
        );
        $this->assertNull(ServerCronJobRun::query()->find($tenDaysOld->id));
    }

    public function test_no_op_when_no_old_runs(): void
    {
        $exit = Artisan::call('dply:prune-cron-job-runs');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Deleted 0', Artisan::output());
    }

    private function makeCronJob(): ServerCronJob
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);

        return ServerCronJob::query()->create([
            'server_id' => $server->id,
            'cron_expression' => '* * * * *',
            'command' => 'echo hi',
            'user' => 'forge',
            'enabled' => true,
        ]);
    }

    private function makeRun(ServerCronJob $job, Carbon $startedAt): ServerCronJobRun
    {
        return ServerCronJobRun::query()->create([
            'server_cron_job_id' => $job->id,
            'run_ulid' => (string) Str::ulid(),
            'trigger' => 'cron',
            'status' => 'success',
            'exit_code' => 0,
            'duration_ms' => 100,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSeconds(1),
        ]);
    }
}
