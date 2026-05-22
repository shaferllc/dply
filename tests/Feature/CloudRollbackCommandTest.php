<?php

declare(strict_types=1);

namespace Tests\Feature\CloudRollbackCommandTest;
use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('rolls back one step by default', function () {
    Queue::fake();
    $site = makeImageSite('current:v3', [
        ['image' => 'old:v1', 'deployed_at' => '2026-05-01T00:00:00Z'],
        ['image' => 'mid:v2', 'deployed_at' => '2026-05-02T00:00:00Z'],
        ['image' => 'current:v3', 'deployed_at' => '2026-05-03T00:00:00Z'],
    ]);

    $exit = Artisan::call('dply:cloud:rollback', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('mid:v2', Artisan::output());
    Queue::assertPushed(RedeployCloudSiteJob::class, fn (RedeployCloudSiteJob $j) => $j->newImage === 'mid:v2');
});
test('rolls back n steps', function () {
    Queue::fake();
    $site = makeImageSite('current:v3', [
        ['image' => 'old:v1', 'deployed_at' => '2026-05-01T00:00:00Z'],
        ['image' => 'mid:v2', 'deployed_at' => '2026-05-02T00:00:00Z'],
        ['image' => 'current:v3', 'deployed_at' => '2026-05-03T00:00:00Z'],
    ]);

    Artisan::call('dply:cloud:rollback', ['site' => $site->name, '--steps' => '2']);

    Queue::assertPushed(RedeployCloudSiteJob::class, fn (RedeployCloudSiteJob $j) => $j->newImage === 'old:v1');
});
test('explicit image overrides steps', function () {
    Queue::fake();
    $site = makeImageSite('current:v3', []);

    Artisan::call('dply:cloud:rollback', ['site' => $site->name, '--image' => 'pinned:v1']);

    Queue::assertPushed(RedeployCloudSiteJob::class, fn (RedeployCloudSiteJob $j) => $j->newImage === 'pinned:v1');
});
test('no op when target equals current', function () {
    Queue::fake();
    $site = makeImageSite('current:v3', []);

    $exit = Artisan::call('dply:cloud:rollback', ['site' => $site->name, '--image' => 'current:v3']);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('already on', Artisan::output());
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('rejects when history too short for steps', function () {
    Queue::fake();
    $site = makeImageSite('current:v3', [
        ['image' => 'current:v3'],
    ]);

    $exit = Artisan::call('dply:cloud:rollback', ['site' => $site->name, '--steps' => '5']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot step back', Artisan::output());
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('rejects source mode site', function () {
    $site = makeImageSite('—', []);
    $site->update([
        'container_image' => null,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);

    $exit = Artisan::call('dply:cloud:rollback', ['site' => $site->name, '--image' => 'whatever']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Source-mode sites have no image history', Artisan::output());
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

    $exit = Artisan::call('dply:cloud:rollback', ['site' => $vmSite->name, '--image' => 'whatever']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('missing site', function () {
    $exit = Artisan::call('dply:cloud:rollback', ['site' => 'nope', '--image' => 'whatever']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', Artisan::output());
});
/**
 * @param  list<array{image: string, deployed_at?: string}>  $history
 */
function makeImageSite(string $current, array $history): Site
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
        'name' => 'edge-image-app',
        'slug' => 'edge-image-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => $current,
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'container_backend_id' => 'fake-app-1',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => $history === [] ? [] : ['container' => ['image_history' => $history]],
    ]);
}
