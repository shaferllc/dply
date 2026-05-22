<?php

declare(strict_types=1);

namespace Tests\Feature\RunSitePhaseCommandTest;
use Mockery;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DeployPhaseRunner;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command runs build phase with default release dir', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
        'runtime' => 'node',
        'repository_path' => '/var/www/jobs',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runBuild')
        ->once()
        ->withArgs(fn (Site $s, string $dir) => $s->id === $site->id && $dir === '/var/www/jobs')
        ->andReturn([['step_id' => '1', 'step_type' => 'npm_ci', 'command' => 'npm ci', 'ok' => true, 'output' => '', 'duration_ms' => 800]]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', ['site' => 'jobs', 'phase' => 'build']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('BUILD phase', $output);
    $this->assertStringContainsString('npm ci', $output);
    $this->assertStringContainsString('Phase build completed', $output);
});
test('command uses release dir override when provided', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
        'runtime' => 'node',
        'repository_path' => '/var/www/jobs',
        'deploy_strategy' => 'atomic',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runRelease')
        ->once()
        ->withArgs(fn (Site $s, string $dir) => $dir === '/var/www/jobs/releases/01HXX')
        ->andReturn([]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', [
        'site' => 'jobs',
        'phase' => 'release',
        '--release-dir' => '/var/www/jobs/releases/01HXX',
    ]);

    expect($exit)->toBe(0);
});
test('command dispatches swap phase', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
        'repository_path' => '/var/www/svc',
        'deploy_strategy' => 'atomic',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runSwap')
        ->once()
        ->andReturn([['step_id' => 'swap', 'step_type' => 'swap', 'command' => 'ln -sfn ...', 'ok' => true, 'output' => '', 'duration_ms' => 12]]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', ['site' => 'svc', 'phase' => 'swap']);

    expect($exit)->toBe(0);
});
test('command dispatches restart phase', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'php',
        'runtime_version' => '8.4',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runRestart')
        ->once()
        ->andReturn([['step_id' => 'restart', 'step_type' => 'restart', 'command' => 'sudo systemctl reload php8.4-fpm', 'ok' => true, 'output' => '', 'duration_ms' => 5]]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', ['site' => 'svc', 'phase' => 'restart']);

    expect($exit)->toBe(0);
});
test('command returns failure when step failed', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'broken',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runBuild')->once()->andReturn([
        ['step_id' => '1', 'step_type' => 'npm_ci', 'command' => 'npm ci', 'ok' => false, 'output' => 'ENOENT', 'duration_ms' => 80],
    ]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', ['site' => 'broken', 'phase' => 'build']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Phase build failed', $output);
    $this->assertStringContainsString('ENOENT', $output);
});
test('command rejects unknown phase', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
    ]);

    $exit = Artisan::call('dply:site:run-phase', ['site' => 'svc', 'phase' => 'compile']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Phase must be one of', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:run-phase', ['site' => 'nonexistent', 'phase' => 'build']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
test('command emits json with per step results', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
        'repository_path' => '/var/www/svc',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runBuild')->once()->andReturn([
        ['step_id' => '1', 'step_type' => 'npm_ci', 'command' => 'npm ci', 'ok' => true, 'output' => 'added 42 packages', 'duration_ms' => 12345],
    ]);
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', [
        'site' => 'svc',
        'phase' => 'build',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['phase'])->toBe('build');
    expect($decoded['release_dir'])->toBe('/var/www/svc');
    expect($decoded['results'])->toHaveCount(1);
    expect($decoded['results'][0]['command'])->toBe('npm ci');
});
test('command emits json error when runner throws', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeployPhaseRunner::class);
    $runner->shouldReceive('runBuild')->once()->andThrow(new \RuntimeException('SSH closed'));
    $this->app->instance(DeployPhaseRunner::class, $runner);

    $exit = Artisan::call('dply:site:run-phase', [
        'site' => 'svc',
        'phase' => 'build',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('SSH closed');
});
