<?php

declare(strict_types=1);

namespace Tests\Feature\WordPress\SwitchWordPressCronHandlerJobTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Modules\RemoteCli\Services\WpCli;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronCommandBuilder;
use App\Services\Servers\ServerCronSynchronizer;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(fn () => Mockery::close());

/** @return array{0: User, 1: Site} */
function cronSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'ssh_user' => 'dply']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Php,
        'document_root' => '/home/dply/blog.example.com',
        'meta' => ['scaffold' => ['framework' => 'wordpress']],
    ]);

    // Preflight passes and the wrapper installs; wp-cli's config set/delete
    // dispatch a background run that "succeeds".
    $preflight = "DPLY_PREFLIGHT: site_release_present pass ok\nDPLY_PREFLIGHT: cron_user_access pass ok";
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput($preflight, 0, false));
    $executor->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    return [$user, $site];
}

function fakeCrontab(Closure $onSync): ServerCronSynchronizer
{
    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('sync')->once()->andReturnUsing($onSync);
    app()->instance(ServerCronSynchronizer::class, $synchronizer);

    return $synchronizer;
}

test('switching to system cron installs a monitored entry before disabling wp-cron', function () {
    [$user, $site] = cronSite();

    $synchronizer = fakeCrontab(function () {
        // The entry is in place, and wp-cron not yet touched.
        expect(ServerCronJob::query()->where('enabled', true)->count())->toBe(1)
            ->and(RemoteCliRun::query()->where('command', 'config set')->count())->toBe(0);

        return "ok\nDPLY_CRON_EXIT:0";
    });

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'system', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    $entry = ServerCronJob::query()->sole();
    expect($entry->cron_expression)->toBe('* * * * *')
        ->and($entry->user)->toBe('dply')
        ->and($entry->overlap_policy)->toBe(ServerCronJob::OVERLAP_SKIP_IF_RUNNING)
        ->and($entry->command)->toBe(SchedulerWrapperScript::wrap($site->id, 'generic', SwitchWordPressCronHandlerJob::command($site)))
        ->and(ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->value('scheduler_kind'))->toBe('generic');

    expect(RemoteCliRun::query()->where('command', 'config set')->sole()->args)->toBe(['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'])
        ->and($site->fresh()->meta['wp_cron']['handler'])->toBe('system_cron')
        ->and($site->fresh()->meta['wp_cron']['error'])->toBeNull();
});

test('the crontab line is one line, runs as the ssh user, and never overlaps', function () {
    [, $site] = cronSite();
    $entry = new ServerCronJob([
        'command' => SwitchWordPressCronHandlerJob::command($site),
        'user' => 'dply',
        'overlap_policy' => ServerCronJob::OVERLAP_SKIP_IF_RUNNING,
    ]);
    $entry->id = 'test-job';

    $segment = app(ServerCronCommandBuilder::class)->crontabCommandSegment($site->server, $entry);

    expect($segment)->not->toContain("\n")
        ->not->toContain('sudo -u')
        ->toContain('flock -n')
        ->toContain('cron event run');
});

test('a failed crontab sync leaves wp-cron on and records the error', function () {
    [$user, $site] = cronSite();

    $synchronizer = fakeCrontab(fn () => throw new \RuntimeException('crontab rejected'));

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'system', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    expect(RemoteCliRun::query()->where('command', 'config set')->count())->toBe(0)
        ->and($site->fresh()->meta['wp_cron']['error'])->toContain('pushing the crontab failed')
        ->and($site->fresh()->meta['wp_cron']['handler'] ?? 'wp_cron')->toBe('wp_cron');
});

test('switching back re-enables wp-cron, then removes the entry and its heartbeat', function () {
    [$user, $site] = cronSite();
    ServerCronJob::query()->create([
        'server_id' => $site->server_id, 'site_id' => $site->id,
        'cron_expression' => '* * * * *', 'command' => SchedulerWrapperScript::wrap($site->id, 'generic', SwitchWordPressCronHandlerJob::command($site)),
        'user' => 'dply', 'enabled' => true,
    ]);
    ServerSchedulerHeartbeat::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'scheduler_kind' => 'generic']);

    $synchronizer = fakeCrontab(function () {
        // wp-cron is already back on, and the entry is disabled so the rewrite drops it.
        expect(RemoteCliRun::query()->where('command', 'config delete')->count())->toBe(1)
            ->and(ServerCronJob::query()->value('enabled'))->toBeFalse();

        return "ok\nDPLY_CRON_EXIT:0";
    });

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'wp-cron', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    expect(ServerCronJob::query()->count())->toBe(0)
        ->and(ServerSchedulerHeartbeat::query()->count())->toBe(0)
        ->and($site->fresh()->meta['wp_cron']['handler'])->toBe('wp_cron');
});
