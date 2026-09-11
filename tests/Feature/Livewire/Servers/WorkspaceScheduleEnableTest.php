<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers\WorkspaceScheduleEnableTest;

use App\Enums\SiteType;
use App\Jobs\EnableSchedulerJob;
use App\Livewire\Servers\WorkspaceSchedule;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronSynchronizer;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

beforeEach(function () {
    Feature::define('workspace.schedule', fn () => true);
    Feature::flushCache();
});

afterEach(fn () => Mockery::close());

/** @return array{User, Server, Site} */
function setupServerWithSite(array $siteAttributes = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_user' => 'dply',
        // Ops-ready, so the page renders the Schedulers tab, not the SSH notice.
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'test-private-key',
    ]);

    return [$user, $server, siteOn($server, $siteAttributes)];
}

function siteOn(Server $server, array $attributes = []): Site
{
    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $server->user_id,
        'organization_id' => $server->organization_id,
    ], $attributes));
}

function siteWithDetectedFramework(Site $site, string $framework, string $language = 'php'): Site
{
    $meta = is_array($site->meta) ? $site->meta : [];
    $meta['vm_runtime'] = ['detected' => ['framework' => $framework, 'language' => $language]];
    $site->update(['meta' => $meta]);

    return $site->fresh();
}

/** @return list<array{key: string, status: string, message: string}> */
function passingChecks(): array
{
    return array_map(
        fn (string $key): array => ['key' => $key, 'status' => 'pass', 'message' => 'ok'],
        ['site_release_present', 'php_binary', 'artisan_file', 'laravel_boots', 'scheduler_has_tasks', 'cron_user_access', 'no_duplicate_scheduler'],
    );
}

/**
 * Fake every SSH touchpoint: preflight results, the wrapper install, wp-cli,
 * and the crontab sync (which reports success the way the real one does).
 *
 * @param  list<array{key: string, status: string, message: string}>  $checks
 */
function fakeBox(array $checks, ?Closure $onSync = null, int $wrapperInstallExit = 0): void
{
    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('wrapper install', $wrapperInstallExit, false));
    $remote->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $preflight = Mockery::mock(PreflightSchedulerOnSite::class, [$remote])->makePartial();
    $preflight->shouldReceive('run')->andReturn($checks);
    app()->instance(PreflightSchedulerOnSite::class, $preflight);

    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('sync')->andReturnUsing($onSync ?? fn (): string => "ok\nDPLY_CRON_EXIT:0");
    app()->instance(ServerCronSynchronizer::class, $synchronizer);
}

/** Click the row's button, then let the page's poll collect the job's result. */
function clickEnable(User $user, Server $server, Site $site, string $customCommand = ''): Testable
{
    $component = Livewire::actingAs($user)->test(WorkspaceSchedule::class, ['server' => $server]);
    if ($customCommand !== '') {
        $component->set('custom_command_site_id', $site->id)->set('custom_command', $customCommand);
    }

    $component->call('enableScheduler', $site->id);

    // Feature tests fake the queue (FakesRemoteServerAccess), so run the
    // queued enable job the way a worker would.
    Queue::pushed(EnableSchedulerJob::class)->each(fn (EnableSchedulerJob $job) => app()->call([$job, 'handle']));

    return $component->call('pollSchedulerRun');
}

test('one click enables the laravel scheduler: wrapped entry, waiting heartbeat, synced crontab', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    fakeBox(passingChecks());

    clickEnable($user, $server, $site)
        ->assertHasNoErrors()
        ->assertSet('enabling_site_id', null)
        ->assertSet('enable_failures', []);

    $cron = ServerCronJob::query()->where('site_id', $site->id)->sole();
    expect($cron->command)->toBe(SchedulerWrapperScript::wrap(
        $site->id,
        'laravel',
        'cd '.$site->effectiveEnvDirectory().' && php artisan schedule:run',
    ))
        ->and($cron->cron_expression)->toBe('* * * * *')
        ->and($cron->overlap_policy)->toBe(ServerCronJob::OVERLAP_ALLOW);

    $heartbeat = ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->sole();
    expect($heartbeat->scheduler_kind)->toBe('laravel')
        ->and($heartbeat->last_tick_at)->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'server.scheduler.enabled']);
});

test('statamic sites get the laravel scheduler', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'statamic');
    fakeBox(passingChecks());

    clickEnable($user, $server, $site);

    expect(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->value('scheduler_kind'))->toBe('laravel')
        ->and(ServerCronJob::query()->where('site_id', $site->id)->value('command'))->toContain('php artisan schedule:run');
});

test('wordpress runs wp cron under the tick, and only then disables wp-cron', function () {
    [$user, $server, $site] = setupServerWithSite([
        'type' => SiteType::Php,
        'document_root' => '/home/dply/blog.example.com',
        'meta' => ['scaffold' => ['framework' => 'wordpress']],
    ]);
    fakeBox(passingChecks(), function (): string {
        // The entry is live before wp-cron is touched.
        expect(RemoteCliRun::query()->where('command', 'config set')->count())->toBe(0);

        return "ok\nDPLY_CRON_EXIT:0";
    });

    clickEnable($user, $server, $site)->assertSet('enable_failures', []);

    $cron = ServerCronJob::query()->where('site_id', $site->id)->sole();
    expect(SchedulerWrapperScript::unwrap($cron->command))->toContain("wp cron event run --path='/home/dply/blog.example.com'")
        ->and($cron->user)->toBe('dply')
        ->and($cron->overlap_policy)->toBe(ServerCronJob::OVERLAP_SKIP_IF_RUNNING);
    expect(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->value('scheduler_kind'))->toBe('generic');
    expect(RemoteCliRun::query()->where('command', 'config set')->sole()->args)->toBe(['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'])
        ->and($site->fresh()->meta['wp_cron']['handler'])->toBe('system_cron');
});

test('a structural preflight failure blocks, and the failed checks show on the row', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    $checks = passingChecks();
    $checks[1] = ['key' => 'php_binary', 'status' => 'fail', 'message' => 'no php binary'];
    fakeBox($checks);

    $component = clickEnable($user, $server, $site);

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0)
        ->and(ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count())->toBe(0)
        ->and($component->get('enable_failures')[$site->id])->toBe([
            ['key' => 'php_binary', 'status' => 'fail', 'message' => 'no php binary'],
        ]);
});

test('advisory warnings do not block', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    $checks = passingChecks();
    $checks[4] = ['key' => 'scheduler_has_tasks', 'status' => 'warn', 'message' => 'no tasks yet'];
    fakeBox($checks);

    clickEnable($user, $server, $site)->assertSet('enable_failures', []);

    expect(ServerCronJob::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('preflight that cannot run over ssh blocks', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    fakeBox([]);

    $component = clickEnable($user, $server, $site);

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0)
        ->and($component->get('enable_failures')[$site->id][0]['message'])->toContain('could not run over SSH');
});

test('a failed wrapper install stops before anything is written', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    fakeBox(passingChecks(), wrapperInstallExit: 1);

    $component = clickEnable($user, $server, $site);

    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0)
        ->and($component->get('enable_failures')[$site->id][0]['message'])->toContain('Could not install the scheduler wrapper');
});

test('a rejected crontab push is reported on the row', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    fakeBox(passingChecks(), fn (): string => "bad minute\nDPLY_CRON_EXIT:1");

    $component = clickEnable($user, $server, $site);

    expect($component->get('enable_failures')[$site->id][0]['message'])->toContain('pushing the crontab failed');
});

test('a stack with no recipe opens the command field, then enables it as a generic scheduler', function () {
    [$user, $server, $site] = setupServerWithSite();

    Livewire::actingAs($user)
        ->test(WorkspaceSchedule::class, ['server' => $server])
        ->call('enableScheduler', $site->id)
        ->assertSet('custom_command_site_id', $site->id);
    expect(ServerCronJob::query()->where('server_id', $server->id)->count())->toBe(0);

    fakeBox(passingChecks());
    clickEnable($user, $server, $site, './bin/run-scheduler')->assertSet('custom_command_site_id', null);

    $cron = ServerCronJob::query()->where('site_id', $site->id)->sole();
    expect(SchedulerWrapperScript::unwrap($cron->command))->toBe('./bin/run-scheduler')
        ->and(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->value('scheduler_kind'))->toBe('generic');
});

test('enable monitoring wraps the existing line in place instead of adding a second', function () {
    [$user, $server, $site] = setupServerWithSite();
    $site = siteWithDetectedFramework($site, 'laravel');
    $existing = ServerCronJob::create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'cron_expression' => '*/2 * * * *',
        'command' => 'cd /srv/app && php artisan schedule:run',
        'user' => 'dply',
        'enabled' => true,
    ]);
    fakeBox(passingChecks());

    clickEnable($user, $server, $site);

    $cron = ServerCronJob::query()->where('site_id', $site->id)->sole();
    expect($cron->id)->toBe($existing->id)
        ->and(SchedulerWrapperScript::unwrap($cron->command))->toBe('cd /srv/app && php artisan schedule:run')
        ->and(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->value('cron_expression'))->toBe('*/2 * * * *');
});

test('a second monitored scheduler of the same kind is refused, not thrown', function () {
    [$user, $server, $site] = setupServerWithSite();
    ServerSchedulerHeartbeat::factory()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'scheduler_kind' => 'generic',
    ]);
    fakeBox(passingChecks());

    $component = clickEnable($user, $server, $site, './bin/other');

    expect(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and($component->get('enable_failures')[$site->id][0]['message'])->toContain('already has a monitored scheduler');
});

test('each row offers the scheduler its detected stack needs', function () {
    [$user, $server, $laravel] = setupServerWithSite();
    siteWithDetectedFramework($laravel, 'laravel');
    $wordpress = siteOn($server, ['meta' => ['scaffold' => ['framework' => 'wordpress']]]);
    $undeployed = siteOn($server);

    $component = Livewire::actingAs($user)->test(WorkspaceSchedule::class, ['server' => $server]);
    $cards = collect($component->viewData('cards'))->keyBy(fn (array $card): string => $card['site']->id);

    expect($cards[$laravel->id]['recipe']->label)->toBe('Laravel scheduler')
        ->and($cards[$wordpress->id]['recipe']->label)->toBe('WordPress cron')
        ->and($cards[$undeployed->id]['recipe'])->toBeNull()
        ->and($cards[$undeployed->id]['deploy_first'])->toBeTrue();

    $component->assertSee('Enable Laravel scheduler')
        ->assertSee('Enable WordPress cron')
        ->assertSee('Deploy the site first');
});

test('a site running schedule:work as a daemon is covered, not offered a second scheduler', function () {
    [$user, $server, $site] = setupServerWithSite();
    siteWithDetectedFramework($site, 'laravel');
    SiteProcess::create([
        'site_id' => $site->id,
        'type' => SiteProcess::TYPE_SCHEDULER,
        'name' => 'imported:scheduler',
        'command' => 'php artisan schedule:work',
        'scale' => 1,
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($user)->test(WorkspaceSchedule::class, ['server' => $server]);

    expect($component->viewData('cards')[0]['state'])->toBe('daemon');
    $component->assertSee('Runs as a schedule:work daemon')->assertDontSee('Enable Laravel scheduler');
});

test('a wrapped line runs the whole command inside the wrapper, and unwraps back', function () {
    $command = "cd /tmp && printf '%s' \"it's fine\"";
    $line = SchedulerWrapperScript::wrap('01ABC', 'laravel', $command);

    expect(SchedulerWrapperScript::unwrap($line))->toBe($command)
        ->and(SchedulerWrapperScript::wrappedKind($line))->toBe('laravel')
        ->and(SchedulerWrapperScript::unwrap('php artisan schedule:run'))->toBe('php artisan schedule:run')
        // Lines written before the sh -c form still unwrap to their tail.
        ->and(SchedulerWrapperScript::unwrap("/usr/local/bin/dply-scheduler-tick '01ABC' 'laravel' -- cd /x && php artisan schedule:run"))
        ->toBe('cd /x && php artisan schedule:run');

    // The real wrapper execs "$@" with no shell, and cron hands the whole line
    // to sh. A stand-in wrapper that does the same proves the `&&` stays inside.
    $fakeWrapper = tempnam(sys_get_temp_dir(), 'tick');
    file_put_contents($fakeWrapper, "#!/bin/sh\nshift 3\nexec \"\$@\"\n");
    chmod($fakeWrapper, 0755);
    $output = shell_exec('/bin/sh -c '.escapeshellarg(str_replace(SchedulerWrapperScript::REMOTE_PATH, $fakeWrapper, $line)));
    unlink($fakeWrapper);

    expect($output)->toBe("it's fine");
});
