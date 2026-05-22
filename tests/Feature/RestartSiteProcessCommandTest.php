<?php

declare(strict_types=1);

namespace Tests\Feature\RestartSiteProcessCommandTest;
use Mockery;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command restarts named process', function () {
    [$site] = makeNodeSiteWithProcess('sidekiq');

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

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Restarted', $output);
    $this->assertStringContainsString('sidekiq', $output);
});
test('command refuses php site', function () {
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

    expect($exit)->toBe(1);
    $this->assertStringContainsString('no systemd units', $output);
});
test('command refuses web process', function () {
    [$site] = makeNodeSiteWithProcess('worker');

    $exit = Artisan::call('dply:site:restart-process', [
        'site' => $site->slug,
        'process' => 'web',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('reload-only', $output);
});
test('command fails when process not found', function () {
    [$site] = makeNodeSiteWithProcess('sidekiq');

    $exit = Artisan::call('dply:site:restart-process', [
        'site' => $site->slug,
        'process' => 'celery',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString("'celery' not found", $output);
});
test('command emits json', function () {
    [$site] = makeNodeSiteWithProcess('sidekiq');

    $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldReceive('restartUnit')->once()->andReturn('Active: active (running)');
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    $exit = Artisan::call('dply:site:restart-process', [
        'site' => $site->slug,
        'process' => 'sidekiq',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['process'])->toBe('sidekiq');
    $this->assertStringContainsString('-sidekiq.service', $decoded['unit']);
});
test('command fails when provisioner throws', function () {
    [$site] = makeNodeSiteWithProcess('sidekiq');

    $provisioner = Mockery::mock(SiteSystemdProvisioner::class);
    $provisioner->shouldReceive('restartUnit')->once()->andThrow(new \RuntimeException('SSH closed'));
    $this->app->instance(SiteSystemdProvisioner::class, $provisioner);

    $exit = Artisan::call('dply:site:restart-process', [
        'site' => $site->slug,
        'process' => 'sidekiq',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('SSH closed');
});
/**
 * @return array{0: Site}
 */
function makeNodeSiteWithProcess(string $name): array
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
