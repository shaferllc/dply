<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers;

use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\PreflightSchedulerOnSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkspaceScheduleEnableTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Server, Site} */
    private function setupServerWithSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
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

        return [$user, $server, $site];
    }

    /**
     * Stub the preflight runner so tests don't try to SSH. Returns the
     * provided result array on every call to run().
     *
     * @param  list<array{key: string, status: string, message: string}>  $results
     */
    private function stubPreflight(array $results): PreflightSchedulerOnSite
    {
        $stub = Mockery::mock(PreflightSchedulerOnSite::class);
        $stub->shouldReceive('run')->andReturn($results);
        $stub->shouldReceive('structuralFailures')->andReturnUsing(
            fn (array $r) => array_values(array_filter(
                $r,
                fn (array $check): bool => $check['status'] === 'fail'
                    && in_array($check['key'], PreflightSchedulerOnSite::STRUCTURAL_CHECKS, true),
            )),
        );
        $stub->shouldReceive('advisoryWarnings')->andReturnUsing(
            fn (array $r) => array_values(array_filter(
                $r,
                fn (array $check): bool => $check['status'] === 'warn',
            )),
        );
        $this->app->instance(PreflightSchedulerOnSite::class, $stub);

        return $stub;
    }

    public function test_enable_with_all_preflight_passes_creates_wrapper_cron_and_heartbeat(): void
    {
        [$user, $server, $site] = $this->setupServerWithSite();
        $this->stubPreflight([
            ['key' => 'site_release_present', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'php_binary', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'artisan_file', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'laravel_boots', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'scheduler_has_tasks', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'cron_user_access', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'no_duplicate_scheduler', 'status' => 'pass', 'message' => 'ok'],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', '* * * * *')
            ->call('enableSchedulerForSite')
            ->assertHasNoErrors();

        // Cron line is wrapper-invoking, not bare.
        $cron = ServerCronJob::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->firstOrFail();
        $this->assertStringContainsString('/usr/local/bin/dply-scheduler-tick', $cron->command);
        $this->assertStringContainsString("'{$site->id}' 'laravel'", $cron->command);
        $this->assertStringContainsString('php artisan schedule:run', $cron->command);
        $this->assertSame('* * * * *', $cron->cron_expression);

        // Heartbeat row pre-created in waiting-for-first-tick state.
        $hb = ServerSchedulerHeartbeat::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->firstOrFail();
        $this->assertNull($hb->last_tick_at, 'Heartbeat starts in waiting state.');
        $this->assertSame(0, $hb->consecutive_misses);
        $this->assertSame('laravel', $hb->scheduler_kind);
        $this->assertSame('* * * * *', $hb->cron_expression);

        $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.enabled']);
    }

    public function test_enable_blocks_on_structural_preflight_failure(): void
    {
        [$user, $server, $site] = $this->setupServerWithSite();
        $this->stubPreflight([
            ['key' => 'site_release_present', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'php_binary', 'status' => 'fail', 'message' => 'no php binary'],
            ['key' => 'artisan_file', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'laravel_boots', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'scheduler_has_tasks', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'cron_user_access', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'no_duplicate_scheduler', 'status' => 'pass', 'message' => 'ok'],
        ]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', '* * * * *')
            ->call('enableSchedulerForSite');

        // No cron / no heartbeat created.
        $this->assertSame(0, ServerCronJob::query()->where('server_id', $server->id)->count());
        $this->assertSame(0, ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count());

        // Preflight results visible in the component state for the blade to render.
        $results = $component->get('preflight_results');
        $this->assertCount(7, $results);
        $this->assertContains('fail', array_column($results, 'status'));
    }

    public function test_enable_allows_advisory_warnings_through(): void
    {
        [$user, $server, $site] = $this->setupServerWithSite();
        $this->stubPreflight([
            ['key' => 'site_release_present', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'php_binary', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'artisan_file', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'laravel_boots', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'scheduler_has_tasks', 'status' => 'warn', 'message' => 'no tasks yet'],
            ['key' => 'cron_user_access', 'status' => 'pass', 'message' => 'ok'],
            ['key' => 'no_duplicate_scheduler', 'status' => 'warn', 'message' => 'duplicate cron line under root'],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', '* * * * *')
            ->call('enableSchedulerForSite')
            ->assertHasNoErrors();

        $this->assertSame(1, ServerCronJob::query()->where('server_id', $server->id)->count(), 'advisory warnings should not block Enable');
        $this->assertSame(1, ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count());
    }

    public function test_enable_blocks_when_preflight_ssh_fails(): void
    {
        [$user, $server, $site] = $this->setupServerWithSite();
        // Empty array == preflight couldn't run (SSH error). Per Q18 mix
        // policy: refuse Enable when we can't verify.
        $this->stubPreflight([]);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', '* * * * *')
            ->call('enableSchedulerForSite');

        $this->assertSame(0, ServerCronJob::query()->where('server_id', $server->id)->count());
        $this->assertSame(0, ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count());
    }

    public function test_enable_rejects_invalid_cron_expression_before_ssh(): void
    {
        [$user, $server, $site] = $this->setupServerWithSite();
        // The preflight stub mustn't be called — bad cron is a pre-SSH guard.
        $stub = Mockery::mock(PreflightSchedulerOnSite::class);
        $stub->shouldNotReceive('run');
        $this->app->instance(PreflightSchedulerOnSite::class, $stub);

        Livewire::actingAs($user)
            ->test(WorkspaceSchedule::class, ['server' => $server])
            ->set('enable_site_id', $site->id)
            ->set('enable_framework', 'laravel')
            ->set('enable_cron_expression', 'utter nonsense')
            ->call('enableSchedulerForSite');

        $this->assertSame(0, ServerCronJob::query()->where('server_id', $server->id)->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
