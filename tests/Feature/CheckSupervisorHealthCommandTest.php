<?php

declare(strict_types=1);

namespace Tests\Feature\CheckSupervisorHealthCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Mockery;

uses(RefreshDatabase::class);

test('no op when disabled', function () {
    Config::set('dply.supervisor_health_check_enabled', false);

    $exit = Artisan::call('dply:supervisor-check-health');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('disabled', Artisan::output());
});
test('skips servers without active programs', function () {
    Config::set('dply.supervisor_health_check_enabled', true);

    $user = User::factory()->create();
    Server::factory()->ready()->create([
        'user_id' => $user->id,
        'ssh_private_key' => 'k',
    ]);

    // Should never call SSH because no supervisor programs exist.
    $mock = Mockery::mock(SupervisorProvisioner::class);
    $mock->shouldNotReceive('fetchSupervisorctlStatus');
    $this->app->instance(SupervisorProvisioner::class, $mock);

    $exit = Artisan::call('dply:supervisor-check-health');

    expect($exit)->toBe(0);
});
test('skips server when supervisor package missing', function () {
    Config::set('dply.supervisor_health_check_enabled', true);

    $user = User::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'ssh_private_key' => 'k',
        'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING,
    ]);
    SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'queue-default',
        'program_type' => 'site',
        'command' => 'php /var/www/app/artisan queue:work',
        'directory' => '/var/www/app',
        'user' => 'forge',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(SupervisorProvisioner::class);
    $mock->shouldNotReceive('fetchSupervisorctlStatus');
    $this->app->instance(SupervisorProvisioner::class, $mock);

    $exit = Artisan::call('dply:supervisor-check-health');

    expect($exit)->toBe(0);
});
test('unhealthy supervisor status records meta and notifies', function () {
    Config::set('dply.supervisor_health_check_enabled', true);
    Notification::fake();
    Cache::flush();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'k',
        'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED ?? 'installed',
    ]);
    SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'queue-default',
        'program_type' => 'site',
        'command' => 'php /var/www/app/artisan queue:work',
        'directory' => '/var/www/app',
        'user' => 'forge',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(SupervisorProvisioner::class);
    $mock->shouldReceive('fetchSupervisorctlStatus')
        ->andReturn("queue-default FATAL    Exited too quickly\n");
    $mock->shouldReceive('analyzeStatusForManagedPrograms')
        ->andReturn(['ok' => false, 'summary' => '1 program in FATAL state']);
    $mock->shouldReceive('hasConfigDrift')->andReturn(false);
    $this->app->instance(SupervisorProvisioner::class, $mock);

    Artisan::call('dply:supervisor-check-health');

    $server->refresh();
    $health = $server->meta['supervisor_health'] ?? null;
    expect($health)->toBeArray();
    expect($health['ok'])->toBeFalse();
    $this->assertStringContainsString('FATAL', $health['summary']);
});
test('ssh failure is logged and does not crash command', function () {
    Config::set('dply.supervisor_health_check_enabled', true);

    $user = User::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'ssh_private_key' => 'k',
        'supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED ?? 'installed',
        'name' => 'edge-1',
    ]);
    SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'slug' => 'queue-default',
        'program_type' => 'site',
        'command' => 'php /var/www/app/artisan queue:work',
        'directory' => '/var/www/app',
        'user' => 'forge',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $mock = Mockery::mock(SupervisorProvisioner::class);
    $mock->shouldReceive('fetchSupervisorctlStatus')
        ->andThrow(new \RuntimeException('connection refused'));
    $this->app->instance(SupervisorProvisioner::class, $mock);

    $exit = Artisan::call('dply:supervisor-check-health');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('edge-1: connection refused', Artisan::output());
});
