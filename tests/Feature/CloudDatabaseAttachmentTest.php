<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDatabaseAttachmentTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\AttachCloudDatabaseJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard shows available databases to attach', function () {
    [$user, $server, $site, $org] = makeContainerSite();
    CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'attachable-db',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Managed databases')
        ->assertSee('attachable-db');
});
test('dashboard lists attached database with detach control', function () {
    [$user, $server, $site, $org] = makeContainerSite();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'attached-db',
    ]);
    $database->sites()->attach($site->id);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('attached-db')
        ->assertSee('Detach');
});
test('attach dispatches job', function () {
    Queue::fake();
    [$user, $server, $site, $org] = makeContainerSite();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_database_attach_id', $database->id)
        ->call('attachContainerDatabase');

    Queue::assertPushed(AttachCloudDatabaseJob::class, function (AttachCloudDatabaseJob $job) use ($database, $site): bool {
        return $job->cloudDatabaseId === $database->id
            && $job->siteId === $site->id
            && $job->detach === false;
    });
});
test('attach with no selection shows toast', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_database_attach_id', '')
        ->call('attachContainerDatabase')
        ->assertDispatched('notify');

    Queue::assertNotPushed(AttachCloudDatabaseJob::class);
});
test('attach rejects database from another org', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();
    $otherOrg = Organization::factory()->create();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $otherOrg->id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_database_attach_id', $database->id)
        ->call('attachContainerDatabase')
        ->assertDispatched('notify');

    Queue::assertNotPushed(AttachCloudDatabaseJob::class);
});
test('attach rejects database that is not active', function () {
    Queue::fake();
    [$user, $server, $site, $org] = makeContainerSite();
    $database = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'status' => CloudDatabase::STATUS_PROVISIONING,
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_database_attach_id', $database->id)
        ->call('attachContainerDatabase')
        ->assertDispatched('notify');

    Queue::assertNotPushed(AttachCloudDatabaseJob::class);
});
test('detach dispatches job with detach flag', function () {
    Queue::fake();
    [$user, $server, $site, $org] = makeContainerSite();
    $database = CloudDatabase::factory()->active()->create(['organization_id' => $org->id]);
    $database->sites()->attach($site->id);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('detachContainerDatabase', $database->id);

    Queue::assertPushed(AttachCloudDatabaseJob::class, function (AttachCloudDatabaseJob $job) use ($database, $site): bool {
        return $job->cloudDatabaseId === $database->id
            && $job->siteId === $site->id
            && $job->detach === true;
    });
});
/**
 * @return array{0: User, 1: Server, 2: Site, 3: Organization}
 */
function makeContainerSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
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
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $server, $site, $org];
}
