<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class TeardownSiteSystemdCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_tears_down_units_for_node_site(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs-app',
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('teardown')
            ->once()
            ->withArgs(fn (Site $s) => $s->id === $site->id)
            ->andReturn(['dply-site-'.$site->id.'.service']);
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:teardown-systemd', ['site' => 'jobs-app']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Tore down 1 unit', $output);
    }

    public function test_command_skips_php_site(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'laravel',
            'runtime' => 'php',
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldNotReceive('teardown');
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:teardown-systemd', ['site' => 'laravel']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Skipped', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:teardown-systemd', ['site' => 'no-such-site']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    public function test_command_emits_json_with_unit_list(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'queue',
            'runtime' => 'python',
            'start_command' => 'gunicorn app:app',
            'internal_port' => 30002,
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('teardown')->once()->andReturn([
            'dply-site-'.$site->id.'.service',
            'dply-site-'.$site->id.'-celery.service',
        ]);
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:teardown-systemd', [
            'site' => 'queue',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertTrue($decoded['ok']);
        $this->assertCount(2, $decoded['units']);
    }

    public function test_command_emits_json_error_when_provisioner_throws(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'broken',
            'runtime' => 'node',
            'start_command' => 'npm start',
        ]);

        $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
        $provisioner->shouldReceive('teardown')->once()->andThrow(new \RuntimeException('SSH closed'));
        $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

        $exit = Artisan::call('dply:site:teardown-systemd', [
            'site' => 'broken',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('SSH closed', $decoded['error']);
    }
}
