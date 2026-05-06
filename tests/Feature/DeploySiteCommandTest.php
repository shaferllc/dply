<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class DeploySiteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_deployment_and_runs_pipeline(): void
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

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Deploy', $output);
        $this->assertStringContainsString('npm ci', $output);
        $this->assertStringContainsString('Deployment succeeded in 8.2s', $output);

        // A SiteDeployment row was created.
        $this->assertSame(1, SiteDeployment::query()->where('site_id', $site->id)->count());
    }

    public function test_command_marks_failed_deployment(): void
    {
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

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Deployment failed', $output);
        $this->assertStringContainsString('ENOENT', $output);
    }

    public function test_command_uses_release_dir_override(): void
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

        $runner = Mockery::mock(DeploymentRunner::class);
        $runner->shouldReceive('run')->once()
            ->withArgs(fn (SiteDeployment $d, string $dir) => $dir === '/var/www/jobs/releases/01HXX')
            ->andReturn(['ok' => true, 'phases' => [], 'total_duration_ms' => 0]);
        $this->app->instance(DeploymentRunner::class, $runner);

        $exit = Artisan::call('dply:site:deploy', [
            'site' => 'jobs',
            '--release-dir' => '/var/www/jobs/releases/01HXX',
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_command_applies_trigger_label_to_deployment(): void
    {
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

        $this->assertSame('ci', SiteDeployment::query()->where('site_id', $site->id)->first()->trigger);
    }

    public function test_command_emits_json_with_deployment_id_and_phases(): void
    {
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

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame($site->id, $decoded['site_id']);
        $this->assertNotEmpty($decoded['deployment_id']);
        $this->assertArrayHasKey('build', $decoded['phases']);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:deploy', ['site' => 'nonexistent']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    public function test_command_marks_deployment_failed_when_runner_throws(): void
    {
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

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('SSH closed', $decoded['error']);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->first();
        $this->assertSame(SiteDeployment::STATUS_FAILED, $deployment->status);
    }
}
