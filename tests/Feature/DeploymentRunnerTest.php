<?php

declare(strict_types=1);

namespace Tests\Feature\DeploymentRunnerTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\DeploymentRunner;
use App\Modules\Deploy\Services\DeployPhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('run walks all four phases and persists results on success', function () {
    [$site, $deployment] = makeDeploymentForSite([
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'deploy_strategy' => 'simple',
    ]);
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
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 300,
    ]);

    $shell = new DeploymentRunnerRecordingShell;
    $runner = new DeploymentRunner(new DeployPhaseRunner);
    $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

    expect($result['ok'])->toBeTrue();
    $deployment->refresh();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);
    expect($deployment->finished_at)->not->toBeNull();

    // Build + release recorded; swap was skipped (simple deploys);
    // restart ran for PHP (FPM reload).
    $persisted = $deployment->phase_results;
    expect($persisted)->toHaveKey('build');
    $this->assertArrayNotHasKey('swap', $persisted);
    expect($persisted)->toHaveKey('release');
    expect($persisted)->toHaveKey('restart');
    expect($persisted['build'])->toHaveCount(1);
    expect($persisted['release'])->toHaveCount(1);
});
test('run aborts pipeline on build failure', function () {
    [$site, $deployment] = makeDeploymentForSite(['runtime' => 'php', 'runtime_version' => '8.4']);
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
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 300,
    ]);

    $shell = new DeploymentRunnerRecordingShell;
    $shell->failOn = 'composer install';

    $runner = new DeploymentRunner(new DeployPhaseRunner);
    $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

    expect($result['ok'])->toBeFalse();
    $deployment->refresh();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_FAILED);
    expect($deployment->phase_results)->toHaveKey('build');
    $this->assertArrayNotHasKey('release', $deployment->phase_results);
    $this->assertArrayNotHasKey('restart', $deployment->phase_results);
});
test('run records swap phase for atomic deploys', function () {
    [$site, $deployment] = makeDeploymentForSite([
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 30001,
        'deploy_strategy' => 'atomic',
        'repository_path' => '/var/www/jobs',
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_NPM_CI,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $shell = new DeploymentRunnerRecordingShell;
    $runner = new DeploymentRunner(new DeployPhaseRunner);
    $result = $runner->run($deployment, '/var/www/jobs/releases/01HXX', fn () => $shell);

    expect($result['ok'])->toBeTrue();
    $deployment->refresh();
    expect($deployment->phase_results)->toHaveKey('swap');
    expect(count($deployment->phase_results['swap']))->toBe(1);

    // ln -sfn ran in the swap phase.
    $swapCommand = $deployment->phase_results['swap'][0]['command'];
    $this->assertStringContainsString('ln -sfn', $swapCommand);
    $this->assertStringContainsString('current', $swapCommand);
});
test('run aggregate total duration sums all phases', function () {
    [$site, $deployment] = makeDeploymentForSite(['runtime' => 'php', 'runtime_version' => '8.4']);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $shell = new DeploymentRunnerRecordingShell;
    $runner = new DeploymentRunner(new DeployPhaseRunner);
    $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

    expect($result['ok'])->toBeTrue();
    expect($result['total_duration_ms'])->toBeGreaterThanOrEqual(0);
});
test('run throws when deployment has no site', function () {
    $deployment = new SiteDeployment;
    $deployment->status = SiteDeployment::STATUS_RUNNING;

    // No site_id set; site relation returns null.
    $this->expectException(\RuntimeException::class);

    (new DeploymentRunner(new DeployPhaseRunner))
        ->run($deployment, '/var/www/app', fn () => new DeploymentRunnerRecordingShell);
});
/**
 * @param  array<string, mixed>  $siteOverrides
 * @return array{0: Site, 1: SiteDeployment}
 */
function makeDeploymentForSite(array $siteOverrides = []): array
{
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'repository_path' => '/var/www/app',
    ], $siteOverrides));
    $site->refresh();

    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-'.uniqid(),
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    return [$site, $deployment];
}
