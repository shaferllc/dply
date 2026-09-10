<?php

declare(strict_types=1);

namespace Tests\Feature\WordPress\SwitchWordPressCronHandlerJobTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\User;
use App\Modules\RemoteCli\Services\WpCli;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerCronCommandBuilder;
use App\Services\Servers\ServerCronSynchronizer;
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

    // wp-cli's config set/delete dispatch a background run; let it "succeed".
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')->andReturn(new ProcessOutput('ok', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    return [$user, $site];
}

test('switching to system cron installs the crontab entry before disabling wp-cron', function () {
    [$user, $site] = cronSite();

    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('sync')->once()->andReturnUsing(function () {
        // The entry is in place, and wp-cron not yet touched.
        expect(ServerCronJob::query()->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)->where('enabled', true)->count())->toBe(1)
            ->and(RemoteCliRun::query()->where('command', 'config set')->count())->toBe(0);

        return 'ok';
    });

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'system', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    $entry = ServerCronJob::query()->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)->sole();
    expect($entry->cron_expression)->toBe('* * * * *')
        ->and($entry->user)->toBe('dply')
        ->and($entry->command)->toContain('PATH=/usr/local/bin')
        ->and($entry->command)->toContain("wp cron event run --path='/home/dply/blog.example.com'")
        ->and($entry->command)->toContain("'--due-now'");

    expect(RemoteCliRun::query()->where('command', 'config set')->sole()->args)->toBe(['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'])
        ->and($site->fresh()->meta['wp_cron']['handler'])->toBe('system_cron');
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

    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('sync')->once()->andThrow(new \RuntimeException('crontab rejected'));

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'system', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    expect(RemoteCliRun::query()->where('command', 'config set')->count())->toBe(0)
        ->and($site->fresh()->meta['wp_cron']['error'])->toContain('crontab rejected')
        ->and($site->fresh()->meta['wp_cron']['handler'] ?? 'wp_cron')->toBe('wp_cron');
});

test('switching back re-enables wp-cron, then removes the entry', function () {
    [$user, $site] = cronSite();
    ServerCronJob::query()->create([
        'server_id' => $site->server_id, 'site_id' => $site->id, 'description' => SwitchWordPressCronHandlerJob::DESCRIPTION,
        'cron_expression' => '* * * * *', 'command' => 'wp cron event run', 'user' => 'dply', 'enabled' => true,
    ]);

    $synchronizer = Mockery::mock(ServerCronSynchronizer::class);
    $synchronizer->shouldReceive('sync')->once()->andReturnUsing(function () {
        // wp-cron is already back on, and the entry is disabled so the rewrite drops it.
        expect(RemoteCliRun::query()->where('command', 'config delete')->count())->toBe(1)
            ->and(ServerCronJob::query()->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)->value('enabled'))->toBeFalse();

        return 'ok';
    });

    (new SwitchWordPressCronHandlerJob((string) $site->id, 'wp-cron', (string) $user->id))->handle($synchronizer, app(WpCli::class));

    expect(ServerCronJob::query()->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)->count())->toBe(0)
        ->and($site->fresh()->meta['wp_cron']['handler'])->toBe('wp_cron');
});
