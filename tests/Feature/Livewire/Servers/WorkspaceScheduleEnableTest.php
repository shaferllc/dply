<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers\WorkspaceScheduleEnableTest;

use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\PreflightSchedulerOnSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

beforeEach(function () {
    Feature::define('workspace.schedule', fn () => true);
    Feature::flushCache();
});

/** @return array{User, Server, Site} */
function setupServerWithSite(): array
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
function stubPreflight(array $results): PreflightSchedulerOnSite
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
    app()->instance(PreflightSchedulerOnSite::class, $stub);

    return $stub;
}
test('enable with all preflight passes creates wrapper cron and heartbeat', function () {
    [$user, $server, $site] = setupServerWithSite();
    stubPreflight([
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
    expect($cron->cron_expression)->toBe('* * * * *');

    // Heartbeat row pre-created in waiting-for-first-tick state.
    $hb = ServerSchedulerHeartbeat::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->firstOrFail();
    expect($hb->last_tick_at)->toBeNull('Heartbeat starts in waiting state.');
    expect($hb->consecutive_misses)->toBe(0);
    expect($hb->scheduler_kind)->toBe('laravel');
    expect($hb->cron_expression)->toBe('* * * * *');

    $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.enabled']);
});
test('enable blocks on structural preflight failure', function () {
    [$user, $server, $site] = setupServerWithSite();
    stubPreflight([
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
    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);
    expect(ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count())->toBe(0);

    // Preflight results visible in the component state for the blade to render.
    $results = $component->get('preflight_results');
    expect($results)->toHaveCount(7);
    expect(array_column($results, 'status'))->toContain('fail');
});
test('enable allows advisory warnings through', function () {
    [$user, $server, $site] = setupServerWithSite();
    stubPreflight([
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

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(1, 'advisory warnings should not block Enable');
    expect(ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count())->toBe(1);
});
test('enable blocks when preflight ssh fails', function () {
    [$user, $server, $site] = setupServerWithSite();

    // Empty array == preflight couldn't run (SSH error). Per Q18 mix
    // policy: refuse Enable when we can't verify.
    stubPreflight([]);

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->set('enable_site_id', $site->id)
        ->set('enable_framework', 'laravel')
        ->set('enable_cron_expression', '* * * * *')
        ->call('enableSchedulerForSite');

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);
    expect(ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count())->toBe(0);
});
test('enable rejects invalid cron expression before ssh', function () {
    [$user, $server, $site] = setupServerWithSite();

    // The preflight stub mustn't be called — bad cron is a pre-SSH guard.
    $stub = Mockery::mock(PreflightSchedulerOnSite::class);
    $stub->shouldNotReceive('run');
    app()->instance(PreflightSchedulerOnSite::class, $stub);

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->set('enable_site_id', $site->id)
        ->set('enable_framework', 'laravel')
        ->set('enable_cron_expression', 'utter nonsense')
        ->call('enableSchedulerForSite');

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);
});
afterEach(function () {
    Mockery::close();
});
