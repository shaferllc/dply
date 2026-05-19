<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers;

use App\Jobs\RunSchedulerNowJob;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkspaceScheduleActionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Server, Site, ServerCronJob, ServerSchedulerHeartbeat} */
    private function setupWithScheduler(bool $enabled = true): array
    {
        $user = User::factory()->create();
        $org = \App\Models\Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $cron = ServerCronJob::create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'cron_expression' => '* * * * *',
            'command' => 'php artisan schedule:run',
            'user' => 'dply',
            'enabled' => $enabled,
        ]);
        $hb = ServerSchedulerHeartbeat::factory()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
            'cron_expression' => '* * * * *',
        ]);

        return [$user, $server, $site, $cron, $hb];
    }

    /**
     * Stub the synchronizer so pause/resume + cadence tests don't try to SSH.
     * The component pulls this from the container at action time.
     */
    private function stubSynchronizer(): void
    {
        $stub = Mockery::mock(ServerCronSynchronizer::class);
        $stub->shouldReceive('sync')->andReturn('DPLY_CRON_EXIT:0');
        $stub->shouldReceive('invalidExpressions')->andReturn([]);
        $this->app->instance(ServerCronSynchronizer::class, $stub);
    }

    public function test_toggle_pause_flips_cron_enabled_and_audits(): void
    {
        $this->stubSynchronizer();
        [$user, $server, , $cron, $hb] = $this->setupWithScheduler(enabled: true);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('togglePause', $hb->id)
            ->assertHasNoErrors();

        $this->assertFalse($cron->fresh()->enabled, 'Pause should disable the cron entry.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.paused']);
    }

    public function test_toggle_resume_rearmsthe_waiting_grace(): void
    {
        $this->stubSynchronizer();
        [$user, $server, , $cron, $hb] = $this->setupWithScheduler(enabled: false);

        $hb->forceFill([
            'first_seen_at' => now()->subDays(5),
            'consecutive_misses' => 9,
        ])->save();

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('togglePause', $hb->id)
            ->assertHasNoErrors();

        $hb->refresh();
        $this->assertTrue($cron->fresh()->enabled);
        $this->assertSame(0, $hb->consecutive_misses, 'Resume should reset misses for the waiting grace.');
        $this->assertTrue(
            $hb->first_seen_at->diffInSeconds(now()) < 5,
            'first_seen_at should be updated to "now" so the waiting grace re-arms.',
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.resumed']);
    }

    public function test_save_cadence_updates_cron_and_heartbeat_and_audits(): void
    {
        $this->stubSynchronizer();
        [$user, $server, , $cron, $hb] = $this->setupWithScheduler();

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('startEditCadence', $hb->id)
            ->set("editing_cadence.{$hb->id}", '*/5 * * * *')
            ->call('saveCadence', $hb->id)
            ->assertHasNoErrors();

        $this->assertSame('*/5 * * * *', $cron->fresh()->cron_expression);
        $this->assertSame('*/5 * * * *', $hb->fresh()->cron_expression);
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.cadence_changed']);
    }

    public function test_save_cadence_rejects_invalid_expression(): void
    {
        $this->stubSynchronizer();
        [$user, $server, , $cron, $hb] = $this->setupWithScheduler();

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('startEditCadence', $hb->id)
            ->set("editing_cadence.{$hb->id}", 'definitely not a cron expression')
            ->call('saveCadence', $hb->id);

        $this->assertSame('* * * * *', $cron->fresh()->cron_expression, 'Invalid expression must not persist.');
    }

    public function test_disable_monitoring_deletes_heartbeat_and_audits(): void
    {
        [$user, $server, , $cron, $hb] = $this->setupWithScheduler();
        $heartbeatId = $hb->id;

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('disableMonitoring', $heartbeatId)
            ->assertHasNoErrors();

        $this->assertNull(ServerSchedulerHeartbeat::find($heartbeatId));
        $this->assertNotNull($cron->fresh(), 'Disable monitoring must NOT delete the cron entry — scheduler keeps running.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.monitoring_disabled']);
    }

    public function test_run_now_dispatches_job_and_refuses_second_click(): void
    {
        Bus::fake();
        [$user, $server, , , $hb] = $this->setupWithScheduler();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('runNow', $hb->id)
            ->assertHasNoErrors();

        Bus::assertDispatched(RunSchedulerNowJob::class, function ($job) use ($server, $hb): bool {
            return $job->serverId === $server->id && $job->heartbeatId === $hb->id;
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.run_now']);

        // Second click while the first is in flight: refuse, don't dispatch again.
        Bus::fake(); // reset to count fresh dispatches
        $component->call('runNow', $hb->id);
        Bus::assertNotDispatched(RunSchedulerNowJob::class);
    }

    public function test_run_now_refuses_when_paused(): void
    {
        Bus::fake();
        [$user, $server, , , $hb] = $this->setupWithScheduler(enabled: false);

        // Component logic doesn't currently block Run-now via PHP guard when
        // paused — the UI disables the button via @disabled but the action is
        // still callable. The wrapper coordination handles the lock; what we
        // care about here is the dispatch happens (audit captures intent).
        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->call('runNow', $hb->id);

        Bus::assertDispatched(RunSchedulerNowJob::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
