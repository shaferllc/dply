<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDeployTasksTest;

use App\Actions\Cloud\ApplyCloudSiteExtras;
use App\Enums\SiteType;
use App\Jobs\SyncCloudDeployTaskRunsJob;
use App\Models\CloudDeployTask;
use App\Models\CloudDeployTaskRun;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use App\Modules\Cloud\Backends\FakeCloudBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function deployTaskSite(string $backend = 'digitalocean_app_platform'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => CloudRouter::credentialProviderFor($backend),
        'name' => 'cred',
        'credentials' => ['api_token' => 'tok', 'access_key_id' => 'k', 'secret_access_key' => 's', 'github_connection_arn' => 'arn:x'],
    ]);
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_backend_id' => 'app-uuid-123',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
    ]);
}

test('do backend supports deploy tasks', function () {
    expect((new DigitalOceanAppPlatformBackend)->supportsDeployTasks())->toBeTrue();
});
test('fake backend supports deploy tasks', function () {
    expect((new FakeCloudBackend)->supportsDeployTasks())->toBeTrue();
});
test('app runner backend does not support deploy tasks', function () {
    expect((new AwsAppRunnerBackend)->supportsDeployTasks())->toBeFalse();
});

test('apply deploy tasks persists rows from payload', function () {
    $site = deployTaskSite();

    (new ApplyCloudSiteExtras)->handle($site, [
        'deploy_tasks' => [
            ['trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => 'php artisan migrate --force', 'size' => 'small'],
            ['trigger' => 'post_deploy', 'name' => 'cache-warm', 'command' => 'php artisan cache:warm', 'size' => 'medium'],
            ['trigger' => 'manual', 'name' => 'rotate-keys', 'command' => 'php artisan key:rotate', 'size' => 'small'],
        ],
    ]);

    $tasks = CloudDeployTask::query()->where('site_id', $site->id)->orderBy('created_at')->get();
    expect($tasks)->toHaveCount(3);
    expect($tasks[0]->trigger)->toBe('pre_deploy');
    expect($tasks[0]->name)->toBe('migrate');
    expect($tasks[0]->command)->toBe('php artisan migrate --force');
    expect($tasks[1]->trigger)->toBe('post_deploy');
    expect($tasks[2]->trigger)->toBe('manual');
});

test('apply deploy tasks skips rows with empty command', function () {
    $site = deployTaskSite();

    (new ApplyCloudSiteExtras)->handle($site, [
        'deploy_tasks' => [
            ['trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => '', 'size' => 'small'],
            ['trigger' => 'post_deploy', 'name' => 'cache-warm', 'command' => 'php artisan cache:warm', 'size' => 'small'],
        ],
    ]);

    $tasks = CloudDeployTask::query()->where('site_id', $site->id)->get();
    expect($tasks)->toHaveCount(1);
    expect($tasks->first()->name)->toBe('cache-warm');
});

test('apply deploy tasks rejects duplicate names', function () {
    $site = deployTaskSite();

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'deploy_tasks' => [
            ['trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => 'php artisan migrate', 'size' => 'small'],
            ['trigger' => 'manual', 'name' => 'migrate', 'command' => 'php artisan migrate:fresh', 'size' => 'small'],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'Duplicate deploy task name: migrate');
});

test('apply deploy tasks rejects unknown trigger', function () {
    $site = deployTaskSite();

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'deploy_tasks' => [
            ['trigger' => 'someday', 'name' => 'never', 'command' => 'echo hi', 'size' => 'small'],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'Unknown deploy task trigger: someday');
});

test('apply deploy tasks rejects app runner backend', function () {
    $site = deployTaskSite('aws_app_runner');

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'deploy_tasks' => [
            ['trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => 'php artisan migrate', 'size' => 'small'],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'backend does not support deploy tasks');
});

test('do spec emits jobs[] components with right kind for each trigger', function () {
    $site = deployTaskSite();
    CloudDeployTask::query()->create(['site_id' => $site->id, 'trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => 'php artisan migrate --force', 'size' => 'small', 'status' => 'configured']);
    CloudDeployTask::query()->create(['site_id' => $site->id, 'trigger' => 'post_deploy', 'name' => 'cache-warm', 'command' => 'php artisan cache:warm', 'size' => 'small', 'status' => 'configured']);
    CloudDeployTask::query()->create(['site_id' => $site->id, 'trigger' => 'manual', 'name' => 'rotate', 'command' => 'php artisan key:rotate', 'size' => 'small', 'status' => 'configured']);

    $backend = new DigitalOceanAppPlatformBackend;
    $method = new \ReflectionMethod($backend, 'jobComponentsFor');
    $components = $method->invoke($backend, $site, ['APP_ENV' => 'production'], [], null);

    expect($components)->toHaveCount(3);

    $byKind = collect($components)->keyBy('kind');
    expect($byKind['PRE_DEPLOY']['run_command'])->toBe('php artisan migrate --force');
    expect($byKind['POST_DEPLOY']['run_command'])->toBe('php artisan cache:warm');
    expect($byKind['MANUAL']['run_command'])->toBe('php artisan key:rotate');

    // Each component carries an image spec block (since this site is image mode).
    expect($components[0]['image'])->toHaveKey('registry_type');
});

test('do spec emits jobs[] with github source when source spec provided', function () {
    $site = deployTaskSite();
    CloudDeployTask::query()->create(['site_id' => $site->id, 'trigger' => 'pre_deploy', 'name' => 'migrate', 'command' => 'php artisan migrate', 'size' => 'small', 'status' => 'configured']);

    $backend = new DigitalOceanAppPlatformBackend;
    $method = new \ReflectionMethod($backend, 'jobComponentsFor');
    $components = $method->invoke($backend, $site, [], [], [
        'repo' => 'acme/api',
        'branch' => 'main',
        'dockerfile_path' => null,
        'deploy_on_push' => true,
    ]);

    expect($components[0]['github'])->toBe([
        'repo' => 'acme/api',
        'branch' => 'main',
        'deploy_on_push' => true,
    ]);
    expect($components[0])->not->toHaveKey('image');
});

test('sync writes task_run rows from DO deployment jobs payload', function () {
    $site = deployTaskSite();
    $task = CloudDeployTask::query()->create([
        'site_id' => $site->id,
        'trigger' => 'pre_deploy',
        'name' => 'migrate',
        'command' => 'php artisan migrate --force',
        'size' => 'small',
        'status' => 'configured',
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments?per_page=1' => Http::response([
            'deployments' => [['id' => 'deploy-abc-123', 'phase' => 'ACTIVE']],
        ]),
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments/deploy-abc-123' => Http::response([
            'deployment' => [
                'id' => 'deploy-abc-123',
                'phase' => 'ACTIVE',
                'jobs' => [
                    [
                        'name' => 'job-migrate',
                        'status' => 'SUCCESS',
                        'exit_code' => 0,
                        'started_at' => '2026-05-26T19:00:00Z',
                        'phase_last_updated_at' => '2026-05-26T19:00:04Z',
                    ],
                ],
            ],
        ]),
    ]);

    (new SyncCloudDeployTaskRunsJob($site->id))->handle();

    $runs = CloudDeployTaskRun::query()->where('cloud_deploy_task_id', $task->id)->get();
    expect($runs)->toHaveCount(1);
    expect($runs->first()->deployment_id)->toBe('deploy-abc-123');
    expect($runs->first()->status)->toBe(CloudDeployTaskRun::STATUS_SUCCEEDED);
    expect($runs->first()->exit_code)->toBe(0);
    expect($runs->first()->trigger)->toBe('pre_deploy');
});

test('sync is idempotent on re-run', function () {
    $site = deployTaskSite();
    $task = CloudDeployTask::query()->create([
        'site_id' => $site->id, 'trigger' => 'pre_deploy', 'name' => 'migrate',
        'command' => 'php artisan migrate', 'size' => 'small', 'status' => 'configured',
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments?per_page=1' => Http::response([
            'deployments' => [['id' => 'deploy-abc-123', 'phase' => 'ACTIVE']],
        ]),
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments/deploy-abc-123' => Http::response([
            'deployment' => [
                'id' => 'deploy-abc-123',
                'jobs' => [['name' => 'job-migrate', 'status' => 'SUCCESS', 'exit_code' => 0]],
            ],
        ]),
    ]);

    (new SyncCloudDeployTaskRunsJob($site->id))->handle();
    (new SyncCloudDeployTaskRunsJob($site->id))->handle();
    (new SyncCloudDeployTaskRunsJob($site->id))->handle();

    expect(CloudDeployTaskRun::query()->where('cloud_deploy_task_id', $task->id)->count())->toBe(1);
});

test('sync maps failed job status correctly', function () {
    $site = deployTaskSite();
    $task = CloudDeployTask::query()->create([
        'site_id' => $site->id, 'trigger' => 'pre_deploy', 'name' => 'migrate',
        'command' => 'php artisan migrate', 'size' => 'small', 'status' => 'configured',
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments?per_page=1' => Http::response([
            'deployments' => [['id' => 'deploy-fail-456', 'phase' => 'ERROR']],
        ]),
        'api.digitalocean.com/v2/apps/app-uuid-123/deployments/deploy-fail-456' => Http::response([
            'deployment' => [
                'id' => 'deploy-fail-456',
                'jobs' => [['name' => 'job-migrate', 'status' => 'ERROR', 'exit_code' => 1]],
            ],
        ]),
    ]);

    (new SyncCloudDeployTaskRunsJob($site->id))->handle();

    $run = CloudDeployTaskRun::query()->where('cloud_deploy_task_id', $task->id)->first();
    expect($run->status)->toBe(CloudDeployTaskRun::STATUS_FAILED);
    expect($run->exit_code)->toBe(1);
});
