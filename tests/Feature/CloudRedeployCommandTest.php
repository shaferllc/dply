<?php

declare(strict_types=1);

namespace Tests\Feature\CloudRedeployCommandTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('dispatches redeploy for image mode site', function () {
    Queue::fake();
    $site = makeContainerSite(['container_image' => 'app:v1']);

    $exit = Artisan::call('dply:cloud:redeploy', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Redeploy queued', Artisan::output());
    Queue::assertPushed(RedeployCloudSiteJob::class, fn ($j) => $j->siteId === $site->id && $j->newImage === null);
});
test('dispatches redeploy for source mode site', function () {
    Queue::fake();
    $site = makeContainerSite([
        'container_image' => null,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);

    $exit = Artisan::call('dply:cloud:redeploy', ['site' => $site->name]);

    expect($exit)->toBe(0);
    Queue::assertPushed(RedeployCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
});
test('rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:redeploy', ['site' => $vmSite->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('missing site returns failure', function () {
    $exit = Artisan::call('dply:cloud:redeploy', ['site' => 'nope']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', Artisan::output());
});
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'slug' => 'edge-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'container_backend_id' => 'fake-app-1',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ], $overrides));
}
