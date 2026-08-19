<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Remediations\Services\RemediationCatalog;
use App\Support\Sites\DeployHubFixes;
use App\Support\Sites\SitePipelineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deployHubFixSite(array $siteAttrs = []): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
    ]);

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'runtime_version' => '8.3',
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'version' => '^8.4',
                ],
            ],
        ],
    ], $siteAttrs));
}

function failedDeploy(Site $site, string $log): SiteDeployment
{
    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => $log,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
}

test('matchAll returns every catalog hit, not only the first', function () {
    $log = implode("\n", [
        'your php version (8.3.6) does not satisfy that requirement. requires php >=8.4.1',
        'Class "Redis" not found in vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php',
    ]);

    $codes = collect(app(RemediationCatalog::class)->matchAll($log))->pluck('code')->all();

    expect($codes)->toContain('php_version_too_low')
        ->and($codes)->toContain('php_ext_redis_missing');
});

test('php upgrade and phpredis each get their own card', function () {
    $site = deployHubFixSite();
    $latest = failedDeploy($site, implode("\n", [
        'your php version (8.3.6) does not satisfy that requirement. requires php >=8.4.1',
        'Class "Redis" not found in vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php',
    ]));

    $cards = DeployHubFixes::cards($site->fresh(), $latest, SitePipelineAdvisor::suggestions($site->fresh()));
    $ids = collect($cards)->pluck('id')->all();

    expect($ids)->toBe(['upgrade_php', 'php_ext_redis_missing'])
        ->and(collect($cards)->pluck('source')->all())->toBe(['pipeline', 'remediation'])
        ->and($ids)->not->toContain('install_php_redis')
        ->and($ids)->not->toContain('composer_install')
        ->and($ids)->not->toContain('php_version_too_low');
});

test('pipeline check keeps only missing steps, not the php upgrade', function () {
    $site = deployHubFixSite();

    $steps = DeployHubFixes::pipelineStepSuggestions(SitePipelineAdvisor::suggestions($site->fresh()));

    expect($steps)->toBe([]);
});

test('a redis-only failure does not also list the site fixer', function () {
    $site = deployHubFixSite([
        'runtime_version' => '8.4',
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'version' => '8.4',
                ],
            ],
        ],
    ]);
    $latest = failedDeploy($site, 'Class "Redis" not found in PhpRedisConnector.php');

    $cards = DeployHubFixes::cards($site->fresh(), $latest, SitePipelineAdvisor::suggestions($site->fresh()));
    $ids = collect($cards)->pluck('id')->all();

    expect($ids)->toContain('php_ext_redis_missing')
        ->and($ids)->not->toContain('install_php_redis')
        ->and($ids)->not->toContain('composer_install');
});

test('a completed fixer card stays visible so its output can live in the fix', function () {
    $site = deployHubFixSite([
        'runtime_version' => '8.4',
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'version' => '8.4',
                ],
            ],
        ],
    ]);
    $latest = failedDeploy($site, 'psql: command not found');

    $without = DeployHubFixes::cards($site->fresh(), $latest, [], ['install_pg_client']);
    $with = DeployHubFixes::cards($site->fresh(), $latest, [], ['install_pg_client'], 'install_pg_client');

    expect(collect($without)->pluck('id')->all())->not->toContain('install_pg_client')
        ->and(collect($with)->pluck('id')->all())->toContain('install_pg_client');
});
