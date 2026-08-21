<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessLogDrainTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Serverless\Services\ServerlessLogDrainProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $this->site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $this->user->id,
        'serverless_backend' => Site::SERVERLESS_BACKEND_DPLY,
    ]);

    config([
        'log_drains.dply_realtime.host' => 'logs.dply.test',
        'log_drains.dply_realtime.port' => '5514',
    ]);
});

test('it is unavailable until an endpoint is configured', function () {
    config(['log_drains.dply_realtime.host' => '', 'log_drains.dply_realtime.port' => '']);

    expect(app(ServerlessLogDrainProvisioner::class)->isAvailable())->toBeFalse();
});

/**
 * The gap this closes: a logging binding reaches VM sites through
 * SiteEnvPusher and Edge through EdgeDplyResourceResolver. A function's only
 * channel is the managed environment, so the drain has to land there.
 */
test('enabling puts the drain into the function managed environment', function () {
    app(ServerlessLogDrainProvisioner::class)->enable($this->site);

    $env = (string) $this->site->fresh()->env_file_content;

    expect($env)->toContain('LOG_CHANNEL=papertrail');
    expect($env)->toContain('logs.dply.test');
    expect($env)->toContain('5514');
});

test('enabling records a logging binding on the site', function () {
    app(ServerlessLogDrainProvisioner::class)->enable($this->site);

    expect(app(ServerlessLogDrainProvisioner::class)->isEnabled($this->site->fresh()))->toBeTrue();
});

/**
 * prepare() only defaults LOG_CHANNEL when the key is absent, which is exactly
 * why the drain is merged before it runs. If that ordering ever inverts, the
 * drain would be silently overwritten back to stderr on every deploy.
 */
test('preparing the environment does not overwrite an attached drain', function () {
    app(ServerlessLogDrainProvisioner::class)->enable($this->site);

    $dir = sys_get_temp_dir().'/dply-fn-drain-'.uniqid();
    mkdir($dir, 0777, true);

    app(ServerlessEnvironmentPreparer::class)->prepare($this->site->fresh(), $dir, true);

    expect((string) $this->site->fresh()->env_file_content)
        ->toContain('LOG_CHANNEL=papertrail')
        ->not->toContain('LOG_CHANNEL=stderr');

    // deleteDirectory, not glob+rmdir: prepare() writes a dotfile (.env) that
    // a bare glob does not match.
    \Illuminate\Support\Facades\File::deleteDirectory($dir);
});

test('disabling returns the function to stderr and drops the endpoint', function () {
    $drains = app(ServerlessLogDrainProvisioner::class);
    $drains->enable($this->site);
    $drains->disable($this->site->fresh());

    $env = (string) $this->site->fresh()->env_file_content;

    expect($env)->toContain('LOG_CHANNEL=stderr');
    // The endpoint must go, not just be blanked — a dead PAPERTRAIL_URL= line
    // in the Environment panel is worse than no line.
    expect($env)->not->toContain('logs.dply.test');
    expect($env)->not->toContain('PAPERTRAIL_URL');
    expect($drains->isEnabled($this->site->fresh()))->toBeFalse();
});

test('sync re-asserts the drain after the environment is edited by hand', function () {
    $drains = app(ServerlessLogDrainProvisioner::class);
    $drains->enable($this->site);

    // Operator edits LOG_CHANNEL back to stderr in the Environment panel.
    app(ServerlessEnvironmentPreparer::class)->mergeKeys($this->site->fresh(), ['LOG_CHANNEL' => 'stderr']);

    expect($drains->sync($this->site->fresh()))->toBeTrue();
    expect((string) $this->site->fresh()->env_file_content)->toContain('LOG_CHANNEL=papertrail');
});

test('sync is a no-op for a function with no drain attached', function () {
    expect(app(ServerlessLogDrainProvisioner::class)->sync($this->site))->toBeFalse();
    expect((string) $this->site->fresh()->env_file_content)->not->toContain('PAPERTRAIL_URL');
});
