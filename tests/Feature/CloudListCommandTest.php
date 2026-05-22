<?php

declare(strict_types=1);

namespace Tests\Feature\CloudListCommandTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('lists all cloud sites', function () {
    makeContainerSite('Site A', 'digitalocean_app_platform');
    makeContainerSite('Site B', 'aws_app_runner');
    makeVmSite('VM Site');

    $exit = Artisan::call('dply:cloud:list', ['--json' => true]);

    expect($exit)->toBe(0);
    $payload = json_decode(Artisan::output(), true);
    expect($payload['total'])->toBe(2);
    $names = array_column($payload['sites'], 'site');
    expect($names)->toContain('Site A');
    expect($names)->toContain('Site B');
    expect($names)->not->toContain('VM Site');
});
test('filter by backend', function () {
    makeContainerSite('A', 'digitalocean_app_platform');
    makeContainerSite('B', 'aws_app_runner');

    Artisan::call('dply:cloud:list', [
        '--json' => true,
        '--backend' => 'aws_app_runner',
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('B');
});
test('filter by status', function () {
    makeContainerSite('Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite('Broken', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

    Artisan::call('dply:cloud:list', [
        '--json' => true,
        '--status' => 'failed',
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('Broken');
});
test('unknown status returns failure', function () {
    $exit = Artisan::call('dply:cloud:list', ['--status' => 'bogus']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown --status', Artisan::output());
});
test('source mode site shows source label in json', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Source Site',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => null,
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']],
        ],
    ]);

    Artisan::call('dply:cloud:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['sites'][0]['mode'])->toBe('source');
    expect($payload['sites'][0]['source'])->toBe('acme/api@main');
    expect($payload['sites'][0]['image'])->toBeNull();
});
test('filter mode source', function () {
    makeContainerSite('Source Site', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'container_image' => null,
        'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
    ]);
    makeContainerSite('Image Site', 'digitalocean_app_platform');

    Artisan::call('dply:cloud:list', ['--json' => true, '--mode' => 'source']);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('Source Site');
});
test('filter previews only', function () {
    $parent = makeContainerSite('Parent', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite('Preview', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Artisan::call('dply:cloud:list', ['--json' => true, '--previews' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('Preview');
});
test('filter no previews excludes them', function () {
    $parent = makeContainerSite('Parent', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
    makeContainerSite('Preview', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'meta' => [
            'container' => ['preview_parent_site_id' => $parent->id, 'preview_branch' => 'feature/x'],
        ],
    ]);

    Artisan::call('dply:cloud:list', ['--json' => true, '--no-previews' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(1);
    expect($payload['sites'][0]['site'])->toBe('Parent');
});
test('json includes instances and size', function () {
    makeContainerSite('Sized Site', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
        'meta' => ['container' => ['instance_count' => 3, 'size_tier' => 'large']],
    ]);

    Artisan::call('dply:cloud:list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['sites'][0]['instances'])->toBe(3);
    expect($payload['sites'][0]['size'])->toBe('large');
});
test('unknown mode returns failure', function () {
    $exit = Artisan::call('dply:cloud:list', ['--mode' => 'nope']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Unknown --mode', Artisan::output());
});
test('empty fleet message', function () {
    $exit = Artisan::call('dply:cloud:list');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No cloud sites found', Artisan::output());
});
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(string $name, string $backend, string $status = Site::STATUS_CONTAINER_ACTIVE, array $overrides = []): Site
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
        'name' => $name,
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => $status,
    ], $overrides));
}
function makeVmSite(string $name): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => $name,
        'type' => SiteType::Php,
    ]);
}
