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
});

test('manage tools report shows git upgrade action when git is installed and upgradable', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'git' => ['present' => true, 'version' => 'git version 2.43.0'],
        ],
        'inventory_upgradable_preview' => "git/noble-updates 1:2.43.0-1ubuntu7.3 amd64 [upgradable from: 1:2.43.0-1ubuntu7.2]\n",
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $git = collect($report['generic_tools'])->firstWhere('slug', 'git');
    expect($git)->not->toBeNull()
        ->and($git['action_key'])->toBe('install_git')
        ->and($git['show_action'])->toBeFalse()
        ->and($git['present_action_key'])->toBe('repair_git')
        ->and($git['show_present_action'])->toBeTrue()
        ->and($git['source_control_url'])->toBe(route('profile.source-control'));
});

test('manage tools report hides git upgrade action when git is current', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'git' => ['present' => true, 'version' => 'git version 2.43.0'],
        ],
        'inventory_upgradable_preview' => "curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]\n",
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $git = collect($report['generic_tools'])->firstWhere('slug', 'git');
    expect($git)->not->toBeNull()
        ->and($git['show_present_action'])->toBeFalse();
});

test('manage tools report shows git install action when git is missing', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'git' => ['present' => false, 'version' => null],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $git = collect($report['generic_tools'])->firstWhere('slug', 'git');
    expect($git)->not->toBeNull()
        ->and($git['show_action'])->toBeTrue()
        ->and($git['show_present_action'])->toBeFalse();
});

test('manage tools report surfaces deploy user git identity from probe', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'git' => [
                'present' => true,
                'version' => 'git version 2.43.0',
                'user_name' => 'Deploy Bot',
                'user_email' => 'deploy@example.com',
            ],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    $git = collect($report['generic_tools'])->firstWhere('slug', 'git');
    expect($git['identity_name'])->toBe('Deploy Bot')
        ->and($git['identity_email'])->toBe('deploy@example.com')
        ->and($git['identity_defaults']['name'])->toBeString()
        ->and($git['identity_defaults']['email'])->toContain('@');
});

test('manage tools report shows docker upgrade when docker-ce is upgradable', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'docker' => ['present' => true, 'version' => 'Docker version 27.0.0'],
        ],
        'inventory_upgradable_preview' => "docker-ce/noble 5:27.0.0-1~ubuntu.24.04~noble amd64 [upgradable from: 5:26.0.0]\n",
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $docker = collect($report['generic_tools'])->firstWhere('slug', 'docker');
    expect($docker)->not->toBeNull()
        ->and($docker['show_action'])->toBeFalse()
        ->and($docker['present_action_key'])->toBe('repair_docker')
        ->and($docker['show_present_action'])->toBeTrue()
        ->and($docker['docker_url'])->toBe(route('servers.docker', $server));
});

test('manage tools report hides docker upgrade when apt has no docker packages', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'docker' => ['present' => true, 'version' => 'Docker version 27.0.0'],
        ],
        'inventory_upgradable_preview' => "git/noble 1:2.43.0 amd64 [upgradable from: 1:2.42.0]\n",
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $docker = collect($report['generic_tools'])->firstWhere('slug', 'docker');
    expect($docker['show_present_action'])->toBeFalse();
});

test('manage tools report marks docker preinstalled on docker host kind', function (): void {
    $server = manageToolsReportServer([
        'host_kind' => Server::HOST_KIND_DOCKER,
        'manage_tools' => [
            'docker' => ['present' => true, 'version' => 'Docker version 27.0.0'],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);
    $docker = collect($report['catalog_rows'])->firstWhere('slug', 'docker');

    expect($docker['status_label'])->toBe('Preinstalled')
        ->and($docker['status_tone'])->toBe('emerald');
});

test('manage tools report assigns status tones for preinstalled installed and missing tools', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'git' => ['present' => true, 'version' => 'git version 2.43.0'],
            'docker' => ['present' => true, 'version' => 'Docker version 27.0.0'],
            'wp_cli' => ['present' => false, 'version' => null],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build($server);

    $git = collect($report['catalog_rows'])->firstWhere('slug', 'git');
    $docker = collect($report['catalog_rows'])->firstWhere('slug', 'docker');
    $wpCli = collect($report['catalog_rows'])->firstWhere('slug', 'wp_cli');

    expect($git['status_label'])->toBe('Preinstalled')
        ->and($git['status_tone'])->toBe('emerald')
        ->and($docker['status_label'])->toBe('Installed')
        ->and($docker['status_tone'])->toBe('sage')
        ->and($wpCli['status_label'])->toBe('Not detected')
        ->and($wpCli['status_tone'])->toBe('amber');
});

test('manage tools report shows wp-cli install when missing and update when present', function (): void {
    $actions = config('server_manage.service_actions', []);

    $missing = manageToolsReportServer([
        'manage_tools' => [
            'wp_cli' => ['present' => false, 'version' => null],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $missingReport = app(ServerManageToolsReport::class)->build($missing, $actions);
    $missingWp = collect($missingReport['catalog_rows'])->firstWhere('slug', 'wp_cli');
    expect($missingWp['show_action'])->toBeTrue()
        ->and($missingWp['show_present_action'])->toBeFalse()
        ->and($missingWp['run_url'])->toBeNull();

    $present = manageToolsReportServer([
        'manage_tools' => [
            'wp_cli' => ['present' => true, 'version' => 'WP-CLI 2.10.0'],
        ],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $presentReport = app(ServerManageToolsReport::class)->build($present, $actions);
    $presentWp = collect($presentReport['catalog_rows'])->firstWhere('slug', 'wp_cli');
    expect($presentWp['show_action'])->toBeFalse()
        ->and($presentWp['present_action_key'])->toBe('update_wp_cli')
        ->and($presentWp['show_present_action'])->toBeTrue()
        ->and($presentWp['run_url'])->toBe(route('servers.run', $present));
});

test('manage tools report shows redis-cli upgrade only when redis-tools is upgradable', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'redis_cli' => ['present' => true, 'version' => 'redis-cli 7.0.15'],
        ],
        'manage_redis' => ['present' => true],
        'inventory_upgradable_preview' => "redis-tools/noble-updates 5:7.0.15-1ubuntu0.24.04.1 amd64 [upgradable from: 5:7.0.15-1build1]\n",
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $redis = collect($report['catalog_rows'])->firstWhere('slug', 'redis_cli');
    expect($redis)->not->toBeNull()
        ->and($redis['show_action'])->toBeFalse()
        ->and($redis['present_action_key'])->toBe('repair_redis_cli')
        ->and($redis['show_present_action'])->toBeTrue()
        ->and($redis['caches_url'])->toBe(route('servers.caches', $server));
});

test('manage tools report hides redis-cli upgrade when apt has no redis-tools upgrade', function (): void {
    $server = manageToolsReportServer([
        'manage_tools' => [
            'redis_cli' => ['present' => true, 'version' => 'redis-cli 7.0.15'],
        ],
        'manage_redis' => ['present' => true],
        'inventory_checked_at' => now()->toIso8601String(),
    ]);

    $report = app(ServerManageToolsReport::class)->build(
        $server,
        config('server_manage.service_actions', []),
    );

    $redis = collect($report['catalog_rows'])->firstWhere('slug', 'redis_cli');
    expect($redis['show_present_action'])->toBeFalse()
        ->and($redis['caches_url'])->toBe(route('servers.caches', $server));
});
