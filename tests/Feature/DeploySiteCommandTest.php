<?php

declare(strict_types=1);

namespace Tests\Feature\DeploySiteCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Services\DeploymentRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

uses(RefreshDatabase::class);

test('command creates deployment and runs pipeline', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
        'runtime' => 'node',
        'repository_path' => '/var/www/jobs',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()
        ->andReturn([
            'ok' => true,
            'phases' => [
                'build' => [['step_id' => '1', 'command' => 'npm ci', 'ok' => true, 'output' => '', 'duration_ms' => 8000]],
                'release' => [],
                'restart' => [['step_id' => 'restart', 'command' => 'sudo systemctl restart …', 'ok' => true, 'output' => '', 'duration_ms' => 200]],
            ],
            'total_duration_ms' => 8200,
        ]);
    $this->app->instance(DeploymentRunner::class, $runner);

    $exit = Artisan::call('dply:site:deploy', ['site' => 'jobs']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Deploy', $output);
    $this->assertStringContainsString('npm ci', $output);
    $this->assertStringContainsString('Deployment succeeded in 8.2s', $output);

    // A SiteDeployment row was created.
    expect(SiteDeployment::query()->where('site_id', $site->id)->count())->toBe(1);
});
test('command marks failed deployment', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'broken',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()
        ->andReturn([
            'ok' => false,
            'phases' => [
                'build' => [['step_id' => '1', 'command' => 'npm ci', 'ok' => false, 'output' => 'ENOENT', 'duration_ms' => 80]],
            ],
            'total_duration_ms' => 80,
        ]);
    $this->app->instance(DeploymentRunner::class, $runner);

    $exit = Artisan::call('dply:site:deploy', ['site' => 'broken']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Deployment failed', $output);
    $this->assertStringContainsString('ENOENT', $output);
});
test('command uses release dir override', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
        'runtime' => 'node',
        'repository_path' => '/var/www/jobs',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()
        ->withArgs(fn (SiteDeployment $d, string $dir) => $dir === '/var/www/jobs/releases/01HXX')
        ->andReturn(['ok' => true, 'phases' => [], 'total_duration_ms' => 0]);
    $this->app->instance(DeploymentRunner::class, $runner);

    $exit = Artisan::call('dply:site:deploy', [
        'site' => 'jobs',
        '--release-dir' => '/var/www/jobs/releases/01HXX',
    ]);

    expect($exit)->toBe(0);
});
test('command applies trigger label to deployment', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(['ok' => true, 'phases' => [], 'total_duration_ms' => 0]);
    $this->app->instance(DeploymentRunner::class, $runner);

    Artisan::call('dply:site:deploy', ['site' => 'svc', '--trigger' => 'ci']);

    expect(SiteDeployment::query()->where('site_id', $site->id)->first()->trigger)->toBe('ci');
});
test('command emits json with deployment id and phases', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'svc',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()->andReturn([
        'ok' => true,
        'phases' => ['build' => [['step_id' => '1', 'ok' => true, 'output' => '', 'duration_ms' => 100]]],
        'total_duration_ms' => 100,
    ]);
    $this->app->instance(DeploymentRunner::class, $runner);

    $exit = Artisan::call('dply:site:deploy', ['site' => 'svc', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['site_id'])->toBe($site->id);
    expect($decoded['deployment_id'])->not->toBeEmpty();
    expect($decoded['phases'])->toHaveKey('build');
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:deploy', ['site' => 'nonexistent']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
test('command marks deployment failed when runner throws', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'broken',
        'runtime' => 'node',
    ]);

    $runner = Mockery::mock(DeploymentRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new \RuntimeException('SSH closed'));
    $this->app->instance(DeploymentRunner::class, $runner);

    $exit = Artisan::call('dply:site:deploy', ['site' => 'broken', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('SSH closed');

    $deployment = SiteDeployment::query()->where('site_id', $site->id)->first();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_FAILED);
});
