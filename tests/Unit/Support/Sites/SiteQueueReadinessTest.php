<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\SiteQueueReadinessTest;

use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use App\Support\Sites\SiteQueueReadiness;
use Illuminate\Support\Collection;

function site(string $env = "QUEUE_CONNECTION=redis\n", bool $restart = true): Site
{
    $s = new Site;
    $s->forceFill([
        'id' => '01hzzzzzzzzzzzzzzzzzzzzzzz',
        'env_file_content' => $env,
        'restart_supervisor_programs_after_deploy' => $restart,
    ]);

    return $s;
}

function worker(bool $active = true): SupervisorProgram
{
    $p = new SupervisorProgram;
    $p->forceFill(['id' => 'p1', 'is_active' => $active, 'command' => 'php artisan queue:work']);

    return $p;
}

function snapshot(int $minutesAgo): SiteQueueSnapshot
{
    $s = new SiteQueueSnapshot;
    $s->forceFill(['queue' => 'default', 'captured_at' => now()->subMinutes($minutesAgo)]);

    return $s;
}

function statuses(array $checks): array
{
    return collect($checks)->pluck('status', 'key')->all();
}

test('a fully wired site passes every check', function () {
    $checks = SiteQueueReadiness::checks(site(), new Collection([worker()]), new Collection, snapshot(2));

    expect(SiteQueueReadiness::isReady($checks))->toBeTrue()
        ->and(statuses($checks))->toBe([
            'driver' => 'ok', 'consumer' => 'ok', 'visibility' => 'ok', 'deploy_restart' => 'ok',
        ]);
});

test('sync fails the driver check even with a healthy worker', function () {
    // The whole point: a running worker against sync consumes nothing.
    $checks = SiteQueueReadiness::checks(site("QUEUE_CONNECTION=sync\n"), new Collection([worker()]), new Collection, snapshot(1));

    expect(statuses($checks)['driver'])->toBe('warn')
        ->and(statuses($checks)['consumer'])->toBe('ok')
        ->and(SiteQueueReadiness::isReady($checks))->toBeFalse();
});

test('an attached pool counts as a consumer even with no on-box worker', function () {
    $pool = new WorkerPool;
    $pool->forceFill(['id' => 'w1', 'name' => 'fleet', 'desired_count' => 2]);

    $checks = SiteQueueReadiness::checks(site(), new Collection, new Collection([$pool]), snapshot(1));

    expect(statuses($checks)['consumer'])->toBe('ok');
});

test('a stopped worker is not a consumer', function () {
    $checks = SiteQueueReadiness::checks(site(), new Collection([worker(active: false)]), new Collection, snapshot(1));

    expect(statuses($checks)['consumer'])->toBe('warn');
});

test('a stale sample flags visibility, not the queue', function () {
    // Distinguishing these stops someone debugging an application that is fine
    // when it is dply's sweep that stopped running.
    expect(statuses(SiteQueueReadiness::checks(site(), new Collection([worker()]), new Collection, snapshot(60)))['visibility'])->toBe('warn')
        ->and(statuses(SiteQueueReadiness::checks(site(), new Collection([worker()]), new Collection, null))['visibility'])->toBe('warn');
});

test('deploy restart off is flagged, because workers keep running old code', function () {
    $checks = SiteQueueReadiness::checks(site(restart: false), new Collection([worker()]), new Collection, snapshot(1));

    expect(statuses($checks)['deploy_restart'])->toBe('warn')
        ->and(collect($checks)->firstWhere('key', 'deploy_restart')['detail'])->toContain('deploys never reach them');
});
