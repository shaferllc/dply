<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\MigrateLegacySchedulersJobTest;

use App\Jobs\MigrateLegacySchedulersJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(fn () => Mockery::close());

function legacyServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'ssh_user' => 'dply']);
}

function legacySite(Server $server, array $attributes = []): Site
{
    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $server->user_id,
        'organization_id' => $server->organization_id,
        'meta' => ['vm_runtime' => ['detected' => ['framework' => 'laravel', 'language' => 'php']]],
    ], $attributes));
}

/** Every SSH call answers with these preflight results and exit code. */
function fakeBox(string $failingCheck = '', int $exit = 0): void
{
    $preflight = collect([...PreflightSchedulerOnSite::STRUCTURAL_CHECKS, ...PreflightSchedulerOnSite::ADVISORY_CHECKS])
        ->map(fn (string $key): string => "DPLY_PREFLIGHT: {$key} ".($key === $failingCheck ? 'fail' : 'pass').' ok')
        ->implode("\n");

    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runInlineBash')->andReturn(new ProcessOutput($preflight, $exit, false));
    $remote->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $crontab = Mockery::mock(ServerCronSynchronizer::class);
    $crontab->shouldReceive('sync')->andReturn("ok\nDPLY_CRON_EXIT:0");
    app()->instance(ServerCronSynchronizer::class, $crontab);
}

function migrate(Server $server): void
{
    app()->call([new MigrateLegacySchedulersJob((string) $server->id), 'handle']);
}

test('a flagged laravel site becomes a wrapped entry and heartbeat, once', function () {
    $server = legacyServer();
    $site = legacySite($server, ['laravel_scheduler' => true]);
    fakeBox();

    migrate($server);
    migrate($server);

    expect(ServerCronJob::query()->sole()->command)->toBe(SchedulerWrapperScript::wrap(
        $site->id,
        'laravel',
        'cd '.$site->effectiveEnvDirectory().' && php artisan schedule:run',
    ))
        ->and(ServerSchedulerHeartbeat::query()->sole()->scheduler_kind)->toBe('laravel')
        ->and($site->fresh()->laravel_scheduler)->toBeFalse();
});

test('a site whose enable fails keeps its flag, so its bare line keeps running', function () {
    $server = legacyServer();
    $site = legacySite($server, ['laravel_scheduler' => true]);
    fakeBox(failingCheck: 'laravel_boots');

    migrate($server);

    expect(ServerCronJob::query()->count())->toBe(0)
        ->and($site->fresh()->laravel_scheduler)->toBeTrue();
});

test('an already-monitored flagged site only loses its flag', function () {
    $server = legacyServer();
    $site = legacySite($server, ['laravel_scheduler' => true]);
    ServerSchedulerHeartbeat::factory()->create(['server_id' => $server->id, 'site_id' => $site->id, 'scheduler_kind' => 'laravel']);
    fakeBox();

    migrate($server);

    expect(ServerCronJob::query()->count())->toBe(0)
        ->and($site->fresh()->laravel_scheduler)->toBeFalse();
});

test('a wrapped line from before the sh -c fix is re-quoted', function () {
    $server = legacyServer();
    $site = legacySite($server);
    $cron = ServerCronJob::query()->create([
        'server_id' => $server->id, 'site_id' => $site->id, 'cron_expression' => '* * * * *', 'user' => 'dply', 'enabled' => true,
        'command' => SchedulerWrapperScript::REMOTE_PATH." '{$site->id}' 'laravel' -- cd /srv/app && php artisan schedule:run",
    ]);
    fakeBox();

    migrate($server);

    expect($cron->fresh()->command)->toBe(SchedulerWrapperScript::wrap($site->id, 'laravel', 'cd /srv/app && php artisan schedule:run'));
});

test('a wrapped line is left alone when the wrapper cannot be installed', function () {
    $server = legacyServer();
    $site = legacySite($server);
    $old = SchedulerWrapperScript::REMOTE_PATH." '{$site->id}' 'laravel' -- cd /srv/app && php artisan schedule:run";
    $cron = ServerCronJob::query()->create([
        'server_id' => $server->id, 'site_id' => $site->id, 'cron_expression' => '* * * * *', 'user' => 'dply', 'enabled' => true,
        'command' => $old,
    ]);
    fakeBox(exit: 1);

    migrate($server);

    expect($cron->fresh()->command)->toBe($old);
});

test('a legacy wordpress system-cron entry is wrapped in place', function () {
    $server = legacyServer();
    $site = legacySite($server, ['meta' => ['scaffold' => ['framework' => 'wordpress']]]);
    $cron = ServerCronJob::query()->create([
        'server_id' => $server->id, 'site_id' => $site->id, 'description' => SwitchWordPressCronHandlerJob::DESCRIPTION,
        'cron_expression' => '* * * * *', 'user' => 'dply', 'enabled' => true,
        'command' => SwitchWordPressCronHandlerJob::command($site),
    ]);
    fakeBox();

    migrate($server);

    expect($cron->fresh()->command)->toBe(SchedulerWrapperScript::wrap($site->id, 'generic', SwitchWordPressCronHandlerJob::command($site)))
        ->and(ServerCronJob::query()->count())->toBe(1)
        ->and(ServerSchedulerHeartbeat::query()->sole()->scheduler_kind)->toBe('generic');
});
