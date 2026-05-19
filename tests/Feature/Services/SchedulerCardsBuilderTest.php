<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\SchedulerHealthEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerCardsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::factory()->create();
    }

    private function makeCron(Server $server, Site $site, string $command, bool $enabled = true): ServerCronJob
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

    public function test_site_with_no_scheduler_renders_empty_card(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id, 'name' => 'marketing']);

        $builder = app(SchedulerCardsBuilder::class);
        $result = $builder->build($server);

        $this->assertCount(1, $result['cards']);
        $this->assertSame('no_scheduler', $result['cards'][0]['state']);
        $this->assertSame(1, $result['stats']['no_scheduler_sites']);
        $this->assertSame(0, $result['stats']['tracked_total']);
    }

    public function test_tracked_scheduler_with_recent_tick_reports_healthy(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $cron = $this->makeCron($server, $site, 'cd /home/dply/site/current && php artisan schedule:run');
        ServerSchedulerHeartbeat::factory()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
            'last_tick_at' => now()->subSeconds(20),
            'first_seen_at' => now()->subDay(),
        ]);

        $result = app(SchedulerCardsBuilder::class)->build($server);

        $this->assertCount(1, $result['cards']);
        $this->assertSame('tracked', $result['cards'][0]['state']);
        $this->assertSame(SchedulerHealthEvaluator::STATE_HEALTHY, $result['cards'][0]['health']);
        $this->assertSame(1, $result['stats']['tracked_total']);
        $this->assertSame(1, $result['stats']['healthy']);
    }

    public function test_paused_cron_renders_paused_card_and_increments_paused_stat(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->makeCron($server, $site, 'php artisan schedule:run', enabled: false);
        ServerSchedulerHeartbeat::factory()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
            'last_tick_at' => now()->subDays(2),
            'first_seen_at' => now()->subWeek(),
        ]);

        $result = app(SchedulerCardsBuilder::class)->build($server);

        $this->assertSame('paused', $result['cards'][0]['state']);
        $this->assertSame(SchedulerHealthEvaluator::STATE_PAUSED, $result['cards'][0]['health']);
        $this->assertSame(1, $result['stats']['paused']);
        $this->assertSame(0, $result['stats']['tracked_total'], 'paused does not count toward tracked_total');
    }

    public function test_scheduler_shaped_cron_with_no_heartbeat_is_detected_unmonitored(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->makeCron($server, $site, 'cd /home/dply/site/current && php artisan schedule:run');
        // No heartbeat — the wrapper isn't installed for this entry.

        $result = app(SchedulerCardsBuilder::class)->build($server);

        $this->assertSame('detected_unmonitored', $result['cards'][0]['state']);
        $this->assertNull($result['cards'][0]['heartbeat']);
        $this->assertSame('laravel', $result['cards'][0]['kind']);
        $this->assertSame(1, $result['stats']['unmonitored']);
    }

    public function test_non_scheduler_cron_is_ignored(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->makeCron($server, $site, '/usr/bin/find /tmp -mtime +7 -delete');

        $result = app(SchedulerCardsBuilder::class)->build($server);

        // No scheduler-shaped cron → the site renders as no_scheduler.
        $this->assertSame('no_scheduler', $result['cards'][0]['state']);
        $this->assertSame(0, $result['stats']['unmonitored']);
    }

    public function test_stale_tick_reports_red_when_consecutive_misses_persisted(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->makeCron($server, $site, 'php artisan schedule:run');
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

        $this->assertSame(SchedulerHealthEvaluator::STATE_RED, $result['cards'][0]['health']);
        $this->assertSame(1, $result['stats']['red']);
        $this->assertSame(1, $result['stats']['tracked_total']);
    }

    public function test_waiting_for_first_tick_state(): void
    {
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->makeCron($server, $site, 'php artisan schedule:run');
        ServerSchedulerHeartbeat::factory()->waitingForFirstTick()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
        ]);

        $result = app(SchedulerCardsBuilder::class)->build($server);

        $this->assertSame('tracked', $result['cards'][0]['state']);
        $this->assertSame(SchedulerHealthEvaluator::STATE_WAITING, $result['cards'][0]['health']);
        $this->assertSame(1, $result['stats']['waiting']);
        $this->assertSame(1, $result['stats']['tracked_total']);
    }

    public function test_multiple_sites_aggregate_correctly(): void
    {
        $server = $this->server();
        [$alpha, $beta, $gamma] = [
            Site::factory()->create(['server_id' => $server->id, 'name' => 'alpha']),
            Site::factory()->create(['server_id' => $server->id, 'name' => 'beta']),
            Site::factory()->create(['server_id' => $server->id, 'name' => 'gamma']),
        ];

        // alpha: healthy tracked
        $this->makeCron($server, $alpha, 'php artisan schedule:run');
        ServerSchedulerHeartbeat::factory()->create([
            'server_id' => $server->id, 'site_id' => $alpha->id,
            'scheduler_kind' => 'laravel',
            'last_tick_at' => now()->subSeconds(10),
            'first_seen_at' => now()->subDay(),
        ]);

        // beta: detected unmonitored
        $this->makeCron($server, $beta, 'cd /home && php artisan schedule:run');

        // gamma: no scheduler at all

        $result = app(SchedulerCardsBuilder::class)->build($server);

        $this->assertCount(3, $result['cards']);
        $this->assertSame(1, $result['stats']['healthy']);
        $this->assertSame(1, $result['stats']['unmonitored']);
        $this->assertSame(1, $result['stats']['no_scheduler_sites']);
        $this->assertSame(1, $result['stats']['tracked_total']);
    }
}
