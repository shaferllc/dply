<?php

declare(strict_types=1);

namespace Tests\Feature\PruneServerCronJobRunsCommandTest;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerCronJobRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('prunes runs older than retention', function () {
    Config::set('cron_workspace.run_retention_days', 30);

    $job = makeCronJob();
    $stale = makeRun($job, now()->subDays(45));
    $recent = makeRun($job, now()->subDays(10));

    $exit = Artisan::call('dply:prune-cron-job-runs');

    expect($exit)->toBe(0);
    expect(ServerCronJobRun::query()->find($stale->id))->toBeNull();
    expect(ServerCronJobRun::query()->find($recent->id))->not->toBeNull();
});
test('minimum retention is seven days', function () {
    Config::set('cron_workspace.run_retention_days', 1);

    $job = makeCronJob();
    $threeDaysOld = makeRun($job, now()->subDays(3));
    $tenDaysOld = makeRun($job, now()->subDays(10));

    Artisan::call('dply:prune-cron-job-runs');

    expect(ServerCronJobRun::query()->find($threeDaysOld->id))->not->toBeNull('3-day-old run should survive even when config says 1-day retention (floored to 7)');
    expect(ServerCronJobRun::query()->find($tenDaysOld->id))->toBeNull();
});
test('no op when no old runs', function () {
    $exit = Artisan::call('dply:prune-cron-job-runs');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Deleted 0', Artisan::output());
});
function makeCronJob(): ServerCronJob
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
function makeRun(ServerCronJob $job, Carbon $startedAt): ServerCronJobRun
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
