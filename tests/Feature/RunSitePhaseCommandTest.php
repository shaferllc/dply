<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\DeployPhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RunSitePhaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_build_phase_with_default_release_dir(): void
    {
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

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('BUILD phase', $output);
        $this->assertStringContainsString('npm ci', $output);
        $this->assertStringContainsString('Phase build completed', $output);
    }

    public function test_command_uses_release_dir_override_when_provided(): void
    {
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

        $this->assertSame(0, $exit);
    }

    public function test_command_dispatches_swap_phase(): void
    {
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

        $this->assertSame(0, $exit);
    }

    public function test_command_dispatches_restart_phase(): void
    {
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

        $this->assertSame(0, $exit);
    }

    public function test_command_returns_failure_when_step_failed(): void
    {
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

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Phase build failed', $output);
        $this->assertStringContainsString('ENOENT', $output);
    }

    public function test_command_rejects_unknown_phase(): void
    {
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

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Phase must be one of', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:run-phase', ['site' => 'nonexistent', 'phase' => 'build']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    public function test_command_emits_json_with_per_step_results(): void
    {
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

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('build', $decoded['phase']);
        $this->assertSame('/var/www/svc', $decoded['release_dir']);
        $this->assertCount(1, $decoded['results']);
        $this->assertSame('npm ci', $decoded['results'][0]['command']);
    }

    public function test_command_emits_json_error_when_runner_throws(): void
    {
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

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('SSH closed', $decoded['error']);
    }
}
