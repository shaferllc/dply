<?php

declare(strict_types=1);

namespace Tests\Feature\DeployPhaseRunnerTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\DeployPhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('run build walks build steps in order and executes in release dir', function () {
    [$site] = makeSite(['runtime' => 'php']);

    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_NPM_RUN,
        'custom_command' => 'build',
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runBuild(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => $shell,
    );

    expect($results)->toHaveCount(2);
    expect($results[0]['ok'])->toBeTrue();
    expect($results[0]['step_type'])->toBe(SiteDeployStep::TYPE_COMPOSER_INSTALL);

    // Both commands ran inside the release dir.
    foreach ($shell->execCalls as $call) {
        $this->assertStringContainsString("cd '/var/www/app/releases/01HXX'", $call);
    }

    // The first command is composer install --no-dev --optimize-autoloader.
    $this->assertStringContainsString('composer install --no-dev --optimize-autoloader', $shell->execCalls[0]);

    // The second command is npm run build.
    $this->assertStringContainsString('npm run build', $shell->execCalls[1]);
});
test('run build aborts on first failure', function () {
    [$site] = makeSite(['runtime' => 'php']);

    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 120,
    ]);

    $shell = new DeployRecordingShell;
    $shell->failOn = 'composer install';

    $results = (new DeployPhaseRunner)->runBuild(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => $shell,
    );

    // Only one result — second step never ran.
    expect($results)->toHaveCount(1);
    expect($results[0]['ok'])->toBeFalse();
    expect(count($shell->execCalls))->toBe(1);
});
test('run build skips custom step with blank command', function () {
    [$site] = makeSite(['runtime' => 'go']);

    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'custom_command' => '',
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 60,
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'custom_command' => 'go build -o bin/app ./...',
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runBuild(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => $shell,
    );

    expect($results)->toHaveCount(2);
    expect($results[0]['ok'])->toBeTrue();
    expect(($results[0]['skipped'] ?? false))->toBeTrue();
    expect(count($shell->execCalls))->toBe(1);
    $this->assertStringContainsString('go build', $shell->execCalls[0]);
});
test('run swap skips when deploys are not atomic', function () {
    [$site] = makeSite(['deploy_strategy' => 'simple']);

    $shell = new DeployRecordingShell;
    $result = (new DeployPhaseRunner)->runSwap(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => $shell,
    );

    expect($result)->toBe([]);
    expect($shell->execCalls)->toBe([]);
});
test('run swap flips current symlink for atomic deploys', function () {
    [$site] = makeSite([
        'runtime' => 'node',
        'deploy_strategy' => 'atomic',
        'repository_path' => '/var/www/jobs',
    ]);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runSwap(
        $site,
        '/var/www/jobs/releases/01HXX',
        fn () => $shell,
    );

    expect($results)->toHaveCount(1);
    expect($results[0]['ok'])->toBeTrue();
    expect(count($shell->execCalls))->toBe(1);
    $this->assertStringContainsString("ln -sfn '/var/www/jobs/releases/01HXX' '/var/www/jobs/current'", $shell->execCalls[0]);
});
test('run release uses current symlink for atomic deploys', function () {
    [$site] = makeSite([
        'runtime' => 'php',
        'deploy_strategy' => 'atomic',
        'repository_path' => '/var/www/laravel',
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 300,
    ]);

    $shell = new DeployRecordingShell;
    (new DeployPhaseRunner)->runRelease(
        $site,
        '/var/www/laravel/releases/01HXX',
        fn () => $shell,
    );

    $this->assertStringContainsString("cd '/var/www/laravel/current'", $shell->execCalls[0]);
    $this->assertStringContainsString('php artisan migrate --force', $shell->execCalls[0]);
});
test('run restart reloads php fpm for php sites', function () {
    [$site] = makeSite([
        'runtime' => 'php',
        'runtime_version' => '8.4',
    ]);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

    expect($results)->toHaveCount(1);
    $this->assertStringContainsString('sudo systemctl reload php8.4-fpm', $shell->execCalls[0]);
});
test('run restart restarts systemd unit for node sites', function () {
    [$site] = makeSite([
        'runtime' => 'node',
        'start_command' => 'npm start',
    ]);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

    expect($results)->toHaveCount(1);
    $this->assertStringContainsString('sudo systemctl restart', $shell->execCalls[0]);
    $this->assertStringContainsString($site->id, $shell->execCalls[0]);
});
test('run restart is a noop for static sites', function () {
    [$site] = makeSite(['runtime' => 'static']);

    $shell = new DeployRecordingShell;
    $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

    expect($results)->toBe([]);
    expect($shell->execCalls)->toBe([]);
});
test('runner throws when server is not ready', function () {
    $server = Server::factory()->create([
        'status' => Server::STATUS_PROVISIONING,
        'ssh_private_key' => null,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
        'start_command' => 'npm start',
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_NPM_CI,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    (new DeployPhaseRunner)->runBuild(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => new DeployRecordingShell,
    );
});
test('runner returns empty for phase with no steps', function () {
    [$site] = makeSite(['runtime' => 'php']);

    $shell = new DeployRecordingShell;
    $result = (new DeployPhaseRunner)->runBuild(
        $site,
        '/var/www/app/releases/01HXX',
        fn () => $shell,
    );

    expect($result)->toBe([]);
    expect($shell->execCalls)->toBe([]);
});
/**
 * @param  array<string, mixed>  $overrides
 * @return array{0: Site}
 */
function makeSite(array $overrides = []): array
{
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'runtime' => 'php',
    ], $overrides));

    return [$site];
}
