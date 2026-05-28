<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Services\Servers\ServerManageToolsReport;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function manageToolsReportServer(array $meta = []): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'test-key',
        'meta' => $meta,
    ]);
}

function seedPhpStack(Server $server): void
{
    $run = ServerProvisionRun::create([
        'server_id' => $server->id,
        'attempt' => 1,
        'status' => 'completed',
    ]);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack_summary',
        'label' => 'stack summary',
        'metadata' => ['expected_services' => ['nginx', 'php-fpm'], 'php_version' => '8.3'],
    ]);
    ServerInstalledServices::flushCaches();
}

test('manage tools report hides composer when php is not installed', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'composer' => ['present' => false, 'version' => null],
            'git' => ['present' => true, 'version' => 'git version 2.43.0'],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    $slugs = array_column($report['catalog_rows'], 'slug');
    expect($slugs)->not->toContain('composer')
        ->and($slugs)->toContain('git');
});

test('manage tools report includes composer when php stack is present', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'composer' => ['present' => true, 'version' => 'Composer version 2.7.0'],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);
    seedPhpStack($server);

    $report = app(ServerManageToolsReport::class)->build($server->fresh());

    expect(collect($report['catalog_rows'])->pluck('slug'))->toContain('composer');
});

test('manage tools report shows redis cli when cache service exists', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'redis',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 6379,
    ]);

    $report = app(ServerManageToolsReport::class)->build($server->fresh());

    expect(collect($report['catalog_rows'])->pluck('slug'))->toContain('redis_cli')
        ->and($report['redis_relevant'])->toBeTrue();
});

test('manage tools report hides redis cli without cache or probe data', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    expect(collect($report['catalog_rows'])->pluck('slug'))->not->toContain('redis_cli');
});

test('manage tools report marks probe stale when toolchain keys missing', function (): void {
    $server = manageToolsReportServer([
        'inventory_checked_at' => now()->subHour()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    expect($report['overall'])->toBe('stale')
        ->and($report['probe_stale'])->toBeTrue();
});

test('manage tools report suppresses composer install action when already present', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'composer' => ['present' => true, 'version' => 'Composer version 2.7.0'],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);
    seedPhpStack($server);

    $report = app(ServerManageToolsReport::class)->build(
        $server->fresh(),
        config('server_manage.service_actions', []),
    );

    $composer = collect($report['generic_tools'])->firstWhere('slug', 'composer');
    expect($composer)->not->toBeNull()
        ->and($composer['show_action'])->toBeFalse();

    $git = collect($report['generic_tools'])->firstWhere('slug', 'git');
    expect($git)->not->toBeNull()
        ->and($git['action_key'])->toBe('install_git');
});
