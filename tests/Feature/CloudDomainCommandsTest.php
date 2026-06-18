<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDomainCommandsTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\AttachCloudDomainJob;
use App\Modules\Cloud\Jobs\DetachCloudDomainJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('attach queues job', function () {
    Queue::fake();
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:domain:attach', [
        'site' => $site->name,
        'hostname' => 'api.example.com',
    ]);

    expect($exit)->toBe(0);
    Queue::assertPushed(AttachCloudDomainJob::class, fn ($j) => $j->siteId === $site->id && $j->hostname === 'api.example.com');
});
test('attach normalizes uppercase and strips scheme', function () {
    Queue::fake();
    $site = makeContainerSite();

    Artisan::call('dply:cloud:domain:attach', [
        'site' => $site->name,
        'hostname' => 'https://API.Example.COM/',
    ]);

    Queue::assertPushed(AttachCloudDomainJob::class, fn ($j) => $j->hostname === 'api.example.com');
});
test('attach rejects invalid hostname', function () {
    Queue::fake();
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:domain:attach', [
        'site' => $site->name,
        'hostname' => 'not a hostname',
    ]);

    expect($exit)->toBe(1);
    Queue::assertNotPushed(AttachCloudDomainJob::class);
});
test('detach queues job', function () {
    Queue::fake();
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:domain:detach', [
        'site' => $site->name,
        'hostname' => 'api.example.com',
    ]);

    expect($exit)->toBe(0);
    Queue::assertPushed(DetachCloudDomainJob::class, fn ($j) => $j->siteId === $site->id && $j->hostname === 'api.example.com');
});
test('list emits json with attached domains', function () {
    $site = makeContainerSite([
        'meta' => [
            'container' => [
                'domains' => [
                    'api.example.com' => ['attached_at' => '2026-05-03T00:00:00Z', 'status' => 'verified'],
                    'preview.example.com' => ['attached_at' => '2026-05-04T00:00:00Z', 'status' => 'pending'],
                ],
            ],
        ],
    ]);

    Artisan::call('dply:cloud:domain:list', ['site' => $site->name, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(2);
    $hostnames = array_column($payload['domains'], 'hostname');
    expect($hostnames)->toContain('api.example.com');
    expect($hostnames)->toContain('preview.example.com');
});
test('list empty state', function () {
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:domain:list', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No custom domains attached', Artisan::output());
});
test('attach rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:domain:attach', [
        'site' => $vmSite->name,
        'hostname' => 'api.example.com',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('attach missing site', function () {
    $exit = Artisan::call('dply:cloud:domain:attach', [
        'site' => 'nope',
        'hostname' => 'api.example.com',
    ]);

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
