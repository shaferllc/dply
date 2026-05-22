<?php

declare(strict_types=1);

namespace Tests\Feature\CloudPollStatusCommandTest;
use App\Enums\SiteType;
use App\Jobs\PollCloudStatusJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dispatches job per provisioning site', function () {
    Queue::fake();
    $a = makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
    $b = makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
    makeContainerSite(Site::STATUS_CONTAINER_ACTIVE);

    // skipped by default
    $exit = Artisan::call('dply:cloud:poll-status');

    expect($exit)->toBe(0);
    Queue::assertPushed(PollCloudStatusJob::class, 2);
});
test('include active flag polls active sites too', function () {
    Queue::fake();
    makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
    makeContainerSite(Site::STATUS_CONTAINER_ACTIVE);

    Artisan::call('dply:cloud:poll-status', ['--include-active' => true]);

    Queue::assertPushed(PollCloudStatusJob::class, 2);
});
test('skips sites without backend id', function () {
    Queue::fake();
    makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING, backendId: null);

    Artisan::call('dply:cloud:poll-status');

    Queue::assertNotPushed(PollCloudStatusJob::class);
});
test('no op when no sites match', function () {
    Queue::fake();
    $exit = Artisan::call('dply:cloud:poll-status');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Dispatched 0', Artisan::output());
});
function makeContainerSite(string $status, ?string $backendId = 'app-12345'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_backend_id' => $backendId,
        'container_region' => 'nyc',
        'status' => $status,
    ]);
}
