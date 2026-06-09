<?php

declare(strict_types=1);

namespace Tests\Feature\FleetDoctorCommandTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('clean fleet returns zero with no drift message', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['runtime_defaults' => ['node' => '22']],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'node',
        'database_engine' => 'postgres',
    ]);

    $exit = Artisan::call('dply:fleet:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No drift detected', $output);
});
test('drift returns failure with per server breakdown', function () {
    $clean = Server::factory()->create(['name' => 'clean']);
    $dirty = Server::factory()->create([
        'name' => 'dirty',
        'meta' => ['runtime_defaults' => ['node' => '22']],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $dirty->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);

    // Site on dirty pins an engine NOT registered.
    Site::factory()->create([
        'server_id' => $dirty->id,
        'name' => 'orphan',
        'database_engine' => 'mariadb',
    ]);

    // Site on dirty uses a runtime not in runtime_defaults.
    Site::factory()->create([
        'server_id' => $dirty->id,
        'name' => 'pyapp',
        'runtime' => 'python',
    ]);

    $exit = Artisan::call('dply:fleet:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('dirty', $output);
    $this->assertStringNotContainsString('clean ', $output);
    // clean server isn't in the drift table
});
test('command emits json with totals and per server', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['runtime_defaults' => ['node' => '22']],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'runtime' => 'python',
    ]);

    $exit = Artisan::call('dply:fleet:doctor', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['totals']['servers_checked'])->toBe(1);
    expect($decoded['totals']['servers_with_drift'])->toBe(1);
    expect($decoded['totals']['sites_needing_runtime_install'])->toBe(1);
    expect($decoded['servers'])->toHaveCount(1);
});
test('ready flag excludes pending servers', function () {
    Server::factory()->create(['name' => 'edge-ready', 'status' => Server::STATUS_READY]);
    Server::factory()->create(['name' => 'edge-pending', 'status' => Server::STATUS_PENDING]);

    $exit = Artisan::call('dply:fleet:doctor', ['--ready' => true, '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    $names = array_column($decoded['servers'], 'server_name');
    expect($names)->toContain('edge-ready');
    expect($names)->not->toContain('edge-pending');
});
test('totals include running and long running deploys', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    // Two running deploys: one fresh, one long-running.
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_RUNNING,
        'trigger' => 'manual',
        'started_at' => now()->subMinutes(2),
    ]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_RUNNING,
        'trigger' => 'manual',
        'started_at' => now()->subMinutes(30),
    ]);

    Artisan::call('dply:fleet:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['totals']['running_deploys'])->toBe(2);
    expect($decoded['totals']['long_running_deploys'])->toBe(1);
});
test('failed latest count reflects unrecovered failures', function () {
    $server = Server::factory()->create();
    $broken = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    $recovered = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
    SiteDeployment::query()->create([
        'site_id' => $broken->id,
        'project_id' => $broken->project_id,
        'status' => SiteDeployment::STATUS_FAILED,
        'trigger' => 'manual',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);
    SiteDeployment::query()->create([
        'site_id' => $recovered->id,
        'project_id' => $recovered->project_id,
        'status' => SiteDeployment::STATUS_FAILED,
        'trigger' => 'manual',
        'started_at' => now()->subDay(),
        'finished_at' => now()->subDay(),
    ]);
    SiteDeployment::query()->create([
        'site_id' => $recovered->id,
        'project_id' => $recovered->project_id,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'trigger' => 'manual',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $exit = Artisan::call('dply:fleet:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);
    expect($decoded['totals']['sites_with_failed_latest_deploy'])->toBe(1);
});
