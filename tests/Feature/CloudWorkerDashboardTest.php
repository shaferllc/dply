<?php

declare(strict_types=1);

namespace Tests\Feature\CloudWorkerDashboardTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\SyncCloudWorkersJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeContainerSite(string $backend = 'digitalocean_app_platform'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => CloudRouter::credentialProviderFor($backend),
        'name' => 'cred',
        'credentials' => ['api_token' => 'tok', 'github_connection_arn' => 'arn:x'],
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
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $server, $site];
}
test('workers section renders on do site', function () {
    [$user, $server, $site] = makeContainerSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Workers & scheduler')
        ->assertSee('Add a queue worker')
        ->assertSee('Enable scheduler');
});
test('workers section lists existing workers', function () {
    [$user, $server, $site] = makeContainerSite();
    CloudWorker::factory()->create([
        'site_id' => $site->id,
        'name' => 'my-queue-worker',
        'command' => 'php artisan queue:work redis',
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('my-queue-worker')
        ->assertSee('php artisan queue:work redis');
});
test('workers section shows disabled state on app runner', function () {
    [$user, $server, $site] = makeContainerSite('aws_app_runner');

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Workers & scheduler')
        ->assertSee('Not available on AWS App Runner')
        ->assertDontSee('Add a queue worker');
});
test('add worker control creates worker and dispatches sync', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_worker_command_input', 'php artisan queue:work')
        ->set('container_worker_size_input', 'medium')
        ->set('container_worker_count_input', 2)
        ->call('addContainerWorker');

    $this->assertDatabaseHas('cloud_workers', [
        'site_id' => $site->id,
        'type' => 'worker',
        'size' => 'medium',
        'instance_count' => 2,
    ]);
    Queue::assertPushed(SyncCloudWorkersJob::class);
});
test('enable scheduler control creates scheduler', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('enableContainerScheduler');

    $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
    Queue::assertPushed(SyncCloudWorkersJob::class);
});
test('disable scheduler control removes scheduler', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();
    $scheduler = CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('disableContainerScheduler');

    $this->assertDatabaseMissing('cloud_workers', ['id' => $scheduler->id]);
    Queue::assertPushed(SyncCloudWorkersJob::class);
});
test('scale worker control updates count and dispatches sync', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id, 'size' => 'medium', 'instance_count' => 1]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('scaleContainerWorker', $worker->id, 3);

    expect($worker->fresh()->instance_count)->toBe(3);
    Queue::assertPushed(SyncCloudWorkersJob::class);
});
test('remove worker control deletes worker and dispatches sync', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('removeContainerWorker', $worker->id);

    $this->assertDatabaseMissing('cloud_workers', ['id' => $worker->id]);
    Queue::assertPushed(SyncCloudWorkersJob::class);
});
test('second scheduler via dashboard is rejected', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();
    CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('enableContainerScheduler');

    // Still only one scheduler.
    expect(CloudWorker::query()->where('site_id', $site->id)->where('type', 'scheduler')->count())->toBe(1);
});
