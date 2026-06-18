<?php

declare(strict_types=1);

namespace Tests\Feature\CreateCloudWorkerTest;

use App\Modules\Cloud\Actions\CreateCloudWorker;
use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
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
test('do backend supports workers', function () {
    expect((new DigitalOceanAppPlatformBackend)->supportsWorkers())->toBeTrue();
});
test('fake backend supports workers', function () {
    expect((new FakeCloudBackend)->supportsWorkers())->toBeTrue();
});
test('app runner backend does not support workers', function () {
    expect((new AwsAppRunnerBackend)->supportsWorkers())->toBeFalse();
});
test('app runner sync workers throws', function () {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('App Runner does not support background workers');

    (new AwsAppRunnerBackend)->syncWorkers(new Site, new ProviderCredential);
});
test('rejects worker count above small tier limit', function () {
    Bus::fake();
    $site = containerSite();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('allows at most 1 instance');

    (new CreateCloudWorker)->handle($site, [
        'type' => 'worker',
        'size' => 'small',
        'instance_count' => 3,
    ]);
});
test('creates worker on do site and dispatches sync', function () {
    Bus::fake();
    $site = containerSite();

    $worker = (new CreateCloudWorker)->handle($site, [
        'type' => 'worker',
        'command' => 'php artisan queue:work redis',
        'size' => 'medium',
        'instance_count' => 3,
    ]);

    expect($worker->type)->toBe(CloudWorker::TYPE_WORKER);
    expect($worker->command)->toBe('php artisan queue:work redis');
    expect($worker->size)->toBe('medium');
    expect($worker->instance_count)->toBe(3);
    expect($worker->status)->toBe(CloudWorker::STATUS_PROVISIONING);

    Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
});
test('worker command defaults when blank', function () {
    Bus::fake();
    $site = containerSite();

    $worker = (new CreateCloudWorker)->handle($site, ['type' => 'worker', 'command' => '']);

    expect($worker->command)->toBe('php artisan queue:work');
});
test('creates scheduler pinned to one instance', function () {
    Bus::fake();
    $site = containerSite();

    $worker = (new CreateCloudWorker)->handle($site, [
        'type' => 'scheduler',
        'instance_count' => 9,
    ]);

    expect($worker->type)->toBe(CloudWorker::TYPE_SCHEDULER);
    expect($worker->command)->toBe('php artisan schedule:work');
    expect($worker->instance_count)->toBe(1);
});
test('rejects second scheduler', function () {
    Bus::fake();
    $site = containerSite();
    (new CreateCloudWorker)->handle($site, ['type' => 'scheduler']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('already has a scheduler');
    (new CreateCloudWorker)->handle($site, ['type' => 'scheduler']);
});
test('rejects app runner site', function () {
    Bus::fake();
    $site = containerSite('aws_app_runner');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('App Runner does not support background workers');
    (new CreateCloudWorker)->handle($site, ['type' => 'worker']);
});
test('rejects non container site', function () {
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Php]);

    $this->expectException(\InvalidArgumentException::class);
    (new CreateCloudWorker)->handle($site, ['type' => 'worker']);
});
test('rejects unknown type', function () {
    $site = containerSite();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown worker type');
    (new CreateCloudWorker)->handle($site, ['type' => 'daemon']);
});
test('unknown size falls back to small', function () {
    Bus::fake();
    $site = containerSite();

    $worker = (new CreateCloudWorker)->handle($site, ['type' => 'worker', 'size' => 'enormous']);

    expect($worker->size)->toBe('small');
});
