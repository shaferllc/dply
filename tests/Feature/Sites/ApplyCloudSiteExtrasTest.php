<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ApplyCloudSiteExtrasTest;

use App\Actions\Cloud\ApplyCloudSiteExtras;
use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\CloudScalingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function cloudSiteWithBackend(string $backend = 'digitalocean_app_platform'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => CloudRouter::credentialProviderFor($backend),
        'name' => 'cloud',
        'credentials' => ['api_token' => 'tok'],
    ]);
    $server = Server::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'status' => Server::STATUS_PENDING,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD, 'edge' => ['backend' => $backend]],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'container_backend' => $backend,
        'container_port' => 8080,
        'container_region' => 'nyc1',
        'env_file_content' => '',
        'status' => Site::STATUS_PENDING,
        'meta' => ['container' => ['instance_count' => 1, 'size_tier' => 'small']],
    ]);
}

test('no-ops when no extras are provided', function () {
    $site = cloudSiteWithBackend();
    (new ApplyCloudSiteExtras)->handle($site, []);

    expect(CloudWorker::query()->count())->toBe(0);
    $fresh = $site->fresh();
    expect($fresh->meta['container']['autoscaling'] ?? null)->toBeNull();
    expect($fresh->meta['container']['health_check'] ?? null)->toBeNull();
});

test('creates worker rows from payload', function () {
    $site = cloudSiteWithBackend();
    (new ApplyCloudSiteExtras)->handle($site, [
        'workers' => [
            ['type' => 'worker', 'name' => 'queue', 'command' => 'php artisan queue:work redis', 'size' => 'medium', 'instance_count' => 2],
            ['type' => 'scheduler'],
        ],
    ]);

    $workers = CloudWorker::query()->where('site_id', $site->id)->orderBy('type')->get();
    expect($workers)->toHaveCount(2);
    $scheduler = $workers->firstWhere('type', 'scheduler');
    $queue = $workers->firstWhere('type', 'worker');
    expect($scheduler->command)->toBe(CloudWorker::SCHEDULER_COMMAND);
    expect($scheduler->instance_count)->toBe(1);
    expect($queue->command)->toBe('php artisan queue:work redis');
    expect($queue->instance_count)->toBe(2);
    expect($queue->size)->toBe('medium');
});

test('rejects more than one scheduler', function () {
    $site = cloudSiteWithBackend();
    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'workers' => [['type' => 'scheduler'], ['type' => 'scheduler']],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('rejects workers on aws app runner', function () {
    $site = cloudSiteWithBackend('aws_app_runner');
    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'workers' => [['type' => 'worker']],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('persists autoscaling and health-check into meta', function () {
    $site = cloudSiteWithBackend();
    (new ApplyCloudSiteExtras)->handle($site, [
        'autoscaling' => ['enabled' => true, 'min_instances' => 2, 'max_instances' => 5, 'cpu_percent' => 60],
        'health_check' => ['enabled' => true, 'http_path' => '/up', 'period_seconds' => 15, 'timeout_seconds' => 3, 'failure_threshold' => 2],
    ]);

    $fresh = $site->fresh();
    expect(CloudScalingConfig::autoscaling($fresh))->toMatchArray([
        'enabled' => true, 'min_instances' => 2, 'max_instances' => 5, 'cpu_percent' => 60,
    ]);
    $hc = CloudScalingConfig::healthCheck($fresh);
    expect($hc['enabled'])->toBeTrue();
    expect($hc['http_path'])->toBe('/up');
    expect($hc['period_seconds'])->toBe(15);
    expect($hc['failure_threshold'])->toBe(2);
});

test('autoscaling disabled flag is a no-op', function () {
    $site = cloudSiteWithBackend();
    (new ApplyCloudSiteExtras)->handle($site, [
        'autoscaling' => ['enabled' => false, 'min_instances' => 99],
    ]);

    expect($site->fresh()->meta['container']['autoscaling'] ?? null)->toBeNull();
});

test('attach database mode wires pivot and merges env vars', function () {
    $site = cloudSiteWithBackend();
    $db = CloudDatabase::factory()->active()->create([
        'organization_id' => $site->organization_id,
        'name' => 'main',
    ]);

    (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'attach', 'cloud_database_id' => $db->id],
    ]);

    expect($db->sites()->where('sites.id', $site->id)->exists())->toBeTrue();
    $env = $site->fresh()->env_file_content;
    expect($env)->toContain('DB_CONNECTION=');
    expect($env)->toContain('DB_HOST=');
});

test('attach database rejects DBs from other orgs', function () {
    $site = cloudSiteWithBackend();
    $foreignOrg = Organization::factory()->create();
    $db = CloudDatabase::factory()->create(['organization_id' => $foreignOrg->id]);

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'attach', 'cloud_database_id' => $db->id],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('attach database requires an id', function () {
    $site = cloudSiteWithBackend();

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'attach'],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('database mode none is a no-op', function () {
    $site = cloudSiteWithBackend();
    (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'none'],
    ]);

    expect($site->fresh()->env_file_content)->toBe('');
});

test('create database mode provisions a new DB and pivots it', function () {
    Bus::fake();
    $site = cloudSiteWithBackend();

    (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'create', 'name' => 'fresh', 'engine' => 'postgres', 'size' => 'small', 'region' => 'nyc1'],
    ]);

    $db = CloudDatabase::query()->where('organization_id', $site->organization_id)->where('name', 'fresh')->first();
    expect($db)->not->toBeNull();
    expect($db->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    expect($db->sites()->where('sites.id', $site->id)->exists())->toBeTrue();

    // env vars stay empty since the DB connection block is empty pre-provision;
    // ProvisionCloudDatabaseJob fans out an AttachCloudDatabaseJob on activation.
    expect($site->fresh()->env_file_content)->toBe('');
});

test('create database rejects unknown engine', function () {
    $site = cloudSiteWithBackend();

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'database' => ['mode' => 'create', 'name' => 'fresh', 'engine' => 'oracle'],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('domains are staged as pending until site activates', function () {
    $site = cloudSiteWithBackend();

    (new ApplyCloudSiteExtras)->handle($site, [
        'domains' => ['app.example.com', 'WWW.example.COM'],
    ]);

    $pending = $site->fresh()->meta['container']['pending_domains'] ?? null;
    expect($pending)->toBe(['app.example.com', 'www.example.com']);
});

test('invalid hostname rejected', function () {
    $site = cloudSiteWithBackend();

    expect(fn () => (new ApplyCloudSiteExtras)->handle($site, [
        'domains' => ['not a hostname'],
    ]))->toThrow(\InvalidArgumentException::class);
});

test('empty domains list is a no-op', function () {
    $site = cloudSiteWithBackend();

    (new ApplyCloudSiteExtras)->handle($site, ['domains' => ['', '   ']]);

    expect($site->fresh()->meta['container']['pending_domains'] ?? null)->toBeNull();
});
