<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDeploymentsCommandTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('lists do deployments via http fake', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/deployments?per_page=10' => Http::response([
            'deployments' => [
                ['id' => 'dep-3', 'phase' => 'ACTIVE', 'created_at' => '2026-05-03T10:00:00Z', 'updated_at' => '2026-05-03T10:03:00Z', 'cause_details' => ['type' => 'COMMIT_PUSH']],
                ['id' => 'dep-2', 'phase' => 'SUPERSEDED', 'created_at' => '2026-05-03T08:00:00Z', 'updated_at' => '2026-05-03T08:04:00Z', 'cause_details' => ['type' => 'MANUAL']],
            ],
        ], 200),
    ]);

    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    Artisan::call('dply:cloud:deployments', ['site' => $site->name, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(2);
    expect($payload['deployments'][0]['id'])->toBe('dep-3');
    expect($payload['deployments'][0]['phase'])->toBe('ACTIVE');
    expect($payload['deployments'][0]['cause'])->toBe('COMMIT_PUSH');
});
test('fake cloud returns synthetic entries', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    Artisan::call('dply:cloud:deployments', ['site' => $site->name, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBeGreaterThanOrEqual(1);
    expect($payload['deployments'][0]['phase'])->toBe('ACTIVE');
    expect($payload['deployments'][0]['id'])->toStartWith('fake-dep-');
});
test('limit clamps to 100', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/deployments*' => Http::response([
            'deployments' => [],
        ], 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    Artisan::call('dply:cloud:deployments', ['site' => $site->name, '--limit' => '999']);

    Http::assertSent(function ($req) {
        return str_contains($req->url(), 'per_page=100');
    });
});
test('human output renders table when present', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    Artisan::call('dply:cloud:deployments', ['site' => $site->name]);
    $output = Artisan::output();

    $this->assertStringContainsString('Recent deployments', $output);
    $this->assertStringContainsString('fake-dep-', $output);
});
test('human output empty state', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/deployments*' => Http::response([
            'deployments' => [],
        ], 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    Artisan::call('dply:cloud:deployments', ['site' => $site->name]);
    $this->assertStringContainsString('No deployments yet', Artisan::output());
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

    $exit = Artisan::call('dply:cloud:deployments', ['site' => $vmSite->name]);
    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('missing site', function () {
    $exit = Artisan::call('dply:cloud:deployments', ['site' => 'nope']);
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
