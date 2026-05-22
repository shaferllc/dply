<?php

declare(strict_types=1);

namespace Tests\Feature\TeardownSiteSystemdCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

uses(RefreshDatabase::class);

test('command tears down units for node site', function () {
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

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Tore down 1 unit', $output);
});
test('command skips php site', function () {
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

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Skipped', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:teardown-systemd', ['site' => 'no-such-site']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
test('command emits json with unit list', function () {
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

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['units'])->toHaveCount(2);
});
test('command emits json error when provisioner throws', function () {
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

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('SSH closed');
});
