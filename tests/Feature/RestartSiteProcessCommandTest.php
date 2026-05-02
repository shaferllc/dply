<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RestartSiteProcessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_restarts_named_process(): void
    {
        [$site] = $this->makeNodeSiteWithProcess('sidekiq');

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('restartUnit')
            ->once()
            ->withArgs(fn ($s, string $unit) => str_contains($unit, '-sidekiq.service'))
            ->andReturn('');
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => $site->slug,
            'process' => 'sidekiq',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Restarted', $output);
        $this->assertStringContainsString('sidekiq', $output);
    }

    public function test_command_refuses_php_site(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'laravel-app',
            'runtime' => 'php',
        ]);

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => 'laravel-app',
            'process' => 'horizon',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no systemd units', $output);
    }

    public function test_command_refuses_web_process(): void
    {
        [$site] = $this->makeNodeSiteWithProcess('worker');

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => $site->slug,
            'process' => 'web',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reload-only', $output);
    }

    public function test_command_fails_when_process_not_found(): void
    {
        [$site] = $this->makeNodeSiteWithProcess('sidekiq');

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => $site->slug,
            'process' => 'celery',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("'celery' not found", $output);
    }

    public function test_command_emits_json(): void
    {
        [$site] = $this->makeNodeSiteWithProcess('sidekiq');

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('restartUnit')->once()->andReturn('Active: active (running)');
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => $site->slug,
            'process' => 'sidekiq',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('sidekiq', $decoded['process']);
        $this->assertStringContainsString('-sidekiq.service', $decoded['unit']);
    }

    public function test_command_fails_when_provisioner_throws(): void
    {
        [$site] = $this->makeNodeSiteWithProcess('sidekiq');

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('restartUnit')->once()->andThrow(new \RuntimeException('SSH closed'));
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:restart-process', [
            'site' => $site->slug,
            'process' => 'sidekiq',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('SSH closed', $decoded['error']);
    }

    /**
     * @return array{0: Site}
     */
    private function makeNodeSiteWithProcess(string $name): array
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
            'runtime' => 'node',
        ]);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => $name,
            'command' => 'node worker.js',
        ]);

        return [$site];
    }
}
