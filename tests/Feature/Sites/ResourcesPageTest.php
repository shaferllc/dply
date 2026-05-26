<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ResourcesPageTest;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\SyncCloudWorkersJob;
use App\Livewire\Sites\Resources;
use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Server, 3: Site}
 */
function resourcesFixture(string $backend = 'digitalocean_app_platform'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => \App\Services\Cloud\CloudRouter::credentialProviderFor($backend),
        'name' => 'cloud',
        'credentials' => ['api_token' => 'tok'],
    ]);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD, 'edge' => ['backend' => $backend]],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_port' => 8080,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $org, $server, $site];
}

test('renders an empty state for a fresh container site', function () {
    [$user, $org, $server, $site] = resourcesFixture();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('Resources')
        ->assertSee('No databases attached yet.')
        ->assertSee('No background processes yet.');
});

test('lists attached databases and workers', function () {
    [$user, $org, $server, $site] = resourcesFixture();
    $db = CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'main-pg',
    ]);
    $db->sites()->attach($site->id);
    CloudWorker::query()->create([
        'site_id' => $site->id,
        'type' => CloudWorker::TYPE_WORKER,
        'name' => 'queue-redis',
        'command' => 'php artisan queue:work redis',
        'size' => 'small',
        'instance_count' => 2,
        'status' => CloudWorker::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('main-pg')
        ->assertSee('DB_HOST')
        ->assertSee('queue-redis')
        ->assertSee('php artisan queue:work redis');
});

test('attach existing database dispatches AttachCloudDatabaseJob', function () {
    Bus::fake([AttachCloudDatabaseJob::class]);
    [$user, $org, $server, $site] = resourcesFixture();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id, 'name' => 'side-pg']);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'database-existing')
        ->set('attach_database_id', $db->id)
        ->call('attachExistingDatabase')
        ->assertHasNoErrors();

    Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($job) => $job->siteId === $site->id
        && $job->cloudDatabaseId === $db->id
        && $job->detach === false);
});

test('attach existing database rejects cross-org DB', function () {
    [$user, $org, $server, $site] = resourcesFixture();
    $foreignOrg = Organization::factory()->create();
    $foreign = CloudDatabase::factory()->active()->create(['organization_id' => $foreignOrg->id]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'database-existing')
        ->set('attach_database_id', $foreign->id)
        ->call('attachExistingDatabase');

    // Should have shown a toast error; no DB pivot or job dispatched.
    expect($foreign->sites()->where('sites.id', $site->id)->exists())->toBeFalse();
});

test('create new database provisions row, pivots, and queues activation hook for later', function () {
    Bus::fake();
    [$user, $org, $server, $site] = resourcesFixture();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'database-new')
        ->set('new_database_name', 'fresh-pg')
        ->set('new_database_engine', 'postgres')
        ->set('new_database_size', 'small')
        ->call('createNewDatabase')
        ->assertHasNoErrors();

    $db = CloudDatabase::query()->where('organization_id', $org->id)->where('name', 'fresh-pg')->first();
    expect($db)->not->toBeNull();
    expect($db->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    expect($db->sites()->where('sites.id', $site->id)->exists())->toBeTrue();
});

test('detach database dispatches AttachCloudDatabaseJob with detach flag', function () {
    Bus::fake([AttachCloudDatabaseJob::class]);
    [$user, $org, $server, $site] = resourcesFixture();
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    $db->sites()->attach($site->id);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('detachDatabase', $db->id);

    Bus::assertDispatched(AttachCloudDatabaseJob::class, fn ($job) => $job->siteId === $site->id
        && $job->cloudDatabaseId === $db->id
        && $job->detach === true);
});

test('attach worker creates row and dispatches sync job', function () {
    Bus::fake([SyncCloudWorkersJob::class]);
    [$user, $org, $server, $site] = resourcesFixture();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'worker')
        ->set('worker_name', 'queue')
        ->set('worker_command', 'php artisan queue:work')
        ->call('attachWorker', 'worker')
        ->assertHasNoErrors();

    $worker = CloudWorker::query()->where('site_id', $site->id)->where('name', 'queue')->first();
    expect($worker)->not->toBeNull();
    Bus::assertDispatched(SyncCloudWorkersJob::class);
});

test('attach scheduler enforces the one-per-site rule via the action', function () {
    [$user, $org, $server, $site] = resourcesFixture();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'scheduler')
        ->call('attachWorker', 'scheduler')
        ->assertHasNoErrors();

    // Second attempt — CreateCloudWorker rejects the duplicate.
    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'scheduler')
        ->call('attachWorker', 'scheduler');

    expect(CloudWorker::query()->where('site_id', $site->id)->where('type', CloudWorker::TYPE_SCHEDULER)->count())->toBe(1);
});

test('worker attach on AWS App Runner is rejected', function () {
    [$user, $org, $server, $site] = resourcesFixture('aws_app_runner');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openAttach', 'worker')
        ->set('worker_name', 'q')
        ->set('worker_command', 'echo')
        ->call('attachWorker', 'worker');

    expect(CloudWorker::query()->where('site_id', $site->id)->count())->toBe(0);
});

test('detach worker deletes the row and dispatches sync', function () {
    Bus::fake([SyncCloudWorkersJob::class]);
    [$user, $org, $server, $site] = resourcesFixture();
    $worker = CloudWorker::query()->create([
        'site_id' => $site->id,
        'type' => CloudWorker::TYPE_WORKER,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'size' => 'small',
        'instance_count' => 1,
        'status' => CloudWorker::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('detachWorker', $worker->id);

    expect(CloudWorker::query()->where('id', $worker->id)->exists())->toBeFalse();
    Bus::assertDispatched(SyncCloudWorkersJob::class);
});

test('non-container site 404s', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
        'type' => SiteType::Php,
    ]);

    $this->withoutExceptionHandling();
    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site]);
})->throws(NotFoundHttpException::class);
