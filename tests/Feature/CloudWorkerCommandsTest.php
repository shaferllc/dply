<?php

declare(strict_types=1);

namespace Tests\Feature\CloudWorkerCommandsTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function containerSite(string $backend = 'digitalocean_app_platform'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => CloudRouter::credentialProviderFor($backend),
        'name' => 'cred',
        'credentials' => ['api_token' => 'tok', 'github_connection_arn' => 'arn:x'],
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
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);
}
test('add command creates worker and queues sync', function () {
    Bus::fake();
    $site = containerSite();

    $exit = Artisan::call('dply:cloud:worker:add', [
        '--site' => $site->id,
        '--command' => 'php artisan queue:work',
        '--size' => 'medium',
        '--count' => 2,
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('cloud_workers', [
        'site_id' => $site->id,
        'type' => 'worker',
        'size' => 'medium',
        'instance_count' => 2,
    ]);
    Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
});
test('add command rejects app runner site', function () {
    Bus::fake();
    $site = containerSite('aws_app_runner');

    $exit = Artisan::call('dply:cloud:worker:add', ['--site' => $site->id]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('App Runner does not support background workers', Artisan::output());
    Bus::assertNotDispatched(SyncCloudWorkersJob::class);
});
test('add command fails on unknown site', function () {
    $exit = Artisan::call('dply:cloud:worker:add', ['--site' => 'nope']);
    expect($exit)->toBe(1);
});
test('add scheduler type', function () {
    Bus::fake();
    $site = containerSite();

    $exit = Artisan::call('dply:cloud:worker:add', ['--site' => $site->id, '--type' => 'scheduler']);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
});
test('list command json', function () {
    $site = containerSite();
    CloudWorker::factory()->create(['site_id' => $site->id]);
    CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

    $exit = Artisan::call('dply:cloud:worker:list', ['--site' => $site->id, '--json' => true]);
    expect($exit)->toBe(0);

    $payload = json_decode(Artisan::output(), true);
    expect($payload['total'])->toBe(2);
});
test('list command empty message', function () {
    $site = containerSite();

    $exit = Artisan::call('dply:cloud:worker:list', ['--site' => $site->id]);
    expect($exit)->toBe(0);
    $this->assertStringContainsString('No workers configured', Artisan::output());
});
test('list command requires site', function () {
    $exit = Artisan::call('dply:cloud:worker:list');
    expect($exit)->toBe(1);
});
test('scale command changes count and queues sync', function () {
    Bus::fake();
    $site = containerSite();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id, 'instance_count' => 1]);

    $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => $worker->id, '--count' => 4, '--size' => 'large']);

    expect($exit)->toBe(0);
    $fresh = $worker->fresh();
    expect($fresh->instance_count)->toBe(4);
    expect($fresh->size)->toBe('large');
    Bus::assertDispatched(SyncCloudWorkersJob::class);
});
test('scale command requires a change', function () {
    $site = containerSite();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

    $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => $worker->id]);
    expect($exit)->toBe(1);
});
test('scale command fails on unknown worker', function () {
    $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => 'nope', '--count' => 2]);
    expect($exit)->toBe(1);
});
test('remove command deletes worker and queues sync', function () {
    Bus::fake();
    $site = containerSite();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

    $exit = Artisan::call('dply:cloud:worker:remove', ['worker' => $worker->id]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('cloud_workers', ['id' => $worker->id]);
    Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
});
test('remove command fails on unknown worker', function () {
    $exit = Artisan::call('dply:cloud:worker:remove', ['worker' => 'nope']);
    expect($exit)->toBe(1);
});
test('scheduler enable command creates scheduler', function () {
    Bus::fake();
    $site = containerSite();

    $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
    Bus::assertDispatched(SyncCloudWorkersJob::class);
});
test('scheduler enable rejects app runner site', function () {
    Bus::fake();
    $site = containerSite('aws_app_runner');

    $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);
    expect($exit)->toBe(1);
});
test('scheduler enable rejects second scheduler', function () {
    Bus::fake();
    $site = containerSite();
    CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

    $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);
    expect($exit)->toBe(1);
});
test('scheduler disable command removes scheduler', function () {
    Bus::fake();
    $site = containerSite();
    $scheduler = CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

    $exit = Artisan::call('dply:cloud:scheduler:disable', ['--site' => $site->id]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('cloud_workers', ['id' => $scheduler->id]);
    Bus::assertDispatched(SyncCloudWorkersJob::class);
});
test('scheduler disable is graceful when no scheduler', function () {
    $site = containerSite();

    $exit = Artisan::call('dply:cloud:scheduler:disable', ['--site' => $site->id]);
    expect($exit)->toBe(0);
    $this->assertStringContainsString('no scheduler', Artisan::output());
});
