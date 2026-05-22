<?php

declare(strict_types=1);

namespace Tests\Feature\Services\SchedulerCardsBuilderTest;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\SchedulerHealthEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function server(): Server
{
    return Server::factory()->create();
}
function makeCron(Server $server, Site $site, string $command, bool $enabled = true): ServerCronJob
{
    return ServerCronJob::create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'cron_expression' => '* * * * *',
        'command' => $command,
        'user' => 'dply',
        'enabled' => $enabled,
        'description' => 'test',
    ]);
}
test('site with no scheduler renders empty card', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id, 'name' => 'marketing']);

    $builder = app(SchedulerCardsBuilder::class);
    $result = $builder->build($server);

    expect($result['cards'])->toHaveCount(1);
    expect($result['cards'][0]['state'])->toBe('no_scheduler');
    expect($result['stats']['no_scheduler_sites'])->toBe(1);
    expect($result['stats']['tracked_total'])->toBe(0);
});
test('tracked scheduler with recent tick reports healthy', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $cron = makeCron($server, $site, 'cd /home/dply/site/current && php artisan schedule:run');
    ServerSchedulerHeartbeat::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'scheduler_kind' => 'laravel',
        'last_tick_at' => now()->subSeconds(20),
        'first_seen_at' => now()->subDay(),
    ]);

    $result = app(SchedulerCardsBuilder::class)->build($server);

    expect($result['cards'])->toHaveCount(1);
    expect($result['cards'][0]['state'])->toBe('tracked');
    expect($result['cards'][0]['health'])->toBe(SchedulerHealthEvaluator::STATE_HEALTHY);
    expect($result['stats']['tracked_total'])->toBe(1);
    expect($result['stats']['healthy'])->toBe(1);
});
test('paused cron renders paused card and increments paused stat', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    makeCron($server, $site, 'php artisan schedule:run', enabled: false);
    ServerSchedulerHeartbeat::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'scheduler_kind' => 'laravel',
        'last_tick_at' => now()->subDays(2),
        'first_seen_at' => now()->subWeek(),
    ]);

    $result = app(SchedulerCardsBuilder::class)->build($server);

    expect($result['cards'][0]['state'])->toBe('paused');
    expect($result['cards'][0]['health'])->toBe(SchedulerHealthEvaluator::STATE_PAUSED);
    expect($result['stats']['paused'])->toBe(1);
    expect($result['stats']['tracked_total'])->toBe(0, 'paused does not count toward tracked_total');
});
test('scheduler shaped cron with no heartbeat is detected unmonitored', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    makeCron($server, $site, 'cd /home/dply/site/current && php artisan schedule:run');

    // No heartbeat — the wrapper isn't installed for this entry.
    $result = app(SchedulerCardsBuilder::class)->build($server);

    expect($result['cards'][0]['state'])->toBe('detected_unmonitored');
    expect($result['cards'][0]['heartbeat'])->toBeNull();
    expect($result['cards'][0]['kind'])->toBe('laravel');
    expect($result['stats']['unmonitored'])->toBe(1);
});
test('non scheduler cron is ignored', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    makeCron($server, $site, '/usr/bin/find /tmp -mtime +7 -delete');

    $result = app(SchedulerCardsBuilder::class)->build($server);

    // No scheduler-shaped cron → the site renders as no_scheduler.
    expect($result['cards'][0]['state'])->toBe('no_scheduler');
    expect($result['stats']['unmonitored'])->toBe(0);
});
test('stale tick reports red when consecutive misses persisted', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    makeCron($server, $site, 'php artisan schedule:run');
    ServerSchedulerHeartbeat::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'scheduler_kind' => 'laravel',
        'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        'first_seen_at' => Carbon::parse('2026-05-19T11:00:00Z'),
        'consecutive_misses' => 5,
    ]);

    $now = Carbon::parse('2026-05-19T13:00:00Z');
    $result = app(SchedulerCardsBuilder::class)->build($server, $now);

    expect($result['cards'][0]['health'])->toBe(SchedulerHealthEvaluator::STATE_RED);
    expect($result['stats']['red'])->toBe(1);
    expect($result['stats']['tracked_total'])->toBe(1);
});
test('waiting for first tick state', function () {
    $server = server();
    $site = Site::factory()->create(['server_id' => $server->id]);
    makeCron($server, $site, 'php artisan schedule:run');
    ServerSchedulerHeartbeat::factory()->waitingForFirstTick()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'scheduler_kind' => 'laravel',
    ]);

    $result = app(SchedulerCardsBuilder::class)->build($server);

    expect($result['cards'][0]['state'])->toBe('tracked');
    expect($result['cards'][0]['health'])->toBe(SchedulerHealthEvaluator::STATE_WAITING);
    expect($result['stats']['waiting'])->toBe(1);
    expect($result['stats']['tracked_total'])->toBe(1);
});
test('multiple sites aggregate correctly', function () {
    $server = server();
    [$alpha, $beta, $gamma] = [
        Site::factory()->create(['server_id' => $server->id, 'name' => 'alpha']),
        Site::factory()->create(['server_id' => $server->id, 'name' => 'beta']),
        Site::factory()->create(['server_id' => $server->id, 'name' => 'gamma']),
    ];

    // alpha: healthy tracked
    makeCron($server, $alpha, 'php artisan schedule:run');
    ServerSchedulerHeartbeat::factory()->create([
        'server_id' => $server->id, 'site_id' => $alpha->id,
        'scheduler_kind' => 'laravel',
        'last_tick_at' => now()->subSeconds(10),
        'first_seen_at' => now()->subDay(),
    ]);

    // beta: detected unmonitored
    makeCron($server, $beta, 'cd /home && php artisan schedule:run');

    // gamma: no scheduler at all
    $result = app(SchedulerCardsBuilder::class)->build($server);

    expect($result['cards'])->toHaveCount(3);
    expect($result['stats']['healthy'])->toBe(1);
    expect($result['stats']['unmonitored'])->toBe(1);
    expect($result['stats']['no_scheduler_sites'])->toBe(1);
    expect($result['stats']['tracked_total'])->toBe(1);
});
