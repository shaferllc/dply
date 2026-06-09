<?php

declare(strict_types=1);

namespace Tests\Feature\PollCloudStatusJobTest;

use App\Enums\SiteType;
use App\Jobs\PollCloudStatusJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('active phase transitions status and records url', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => [
                'id' => 'app-12345',
                'phase' => 'ACTIVE',
                'default_ingress' => 'https://acme.ondigitalocean.app',
                'spec' => ['name' => 'acme'],
            ],
        ], 200),
    ]);

    $site = makeProvisioningSite();

    (new PollCloudStatusJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_ACTIVE);
    expect($fresh->meta['container']['live_url'])->toBe('https://acme.ondigitalocean.app');
    expect($fresh->meta['container']['last_phase'])->toBe('ACTIVE');
});
test('error phase transitions to failed', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => ['id' => 'app-12345', 'phase' => 'ERROR'],
        ], 200),
    ]);
    $site = makeProvisioningSite();

    (new PollCloudStatusJob($site->id))->handle();

    expect($site->fresh()->status)->toBe(Site::STATUS_CONTAINER_FAILED);
});
test('intermediate phase keeps provisioning', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => ['id' => 'app-12345', 'phase' => 'BUILDING'],
        ], 200),
    ]);
    $site = makeProvisioningSite();

    (new PollCloudStatusJob($site->id))->handle();

    expect($site->fresh()->status)->toBe(Site::STATUS_CONTAINER_PROVISIONING);
    expect($site->fresh()->meta['container']['last_phase'])->toBe('BUILDING');
});
test('unknown phase does not change status', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([
            'app' => ['id' => 'app-12345', 'phase' => 'SOMETHING_NEW'],
        ], 200),
    ]);
    $site = makeProvisioningSite();

    (new PollCloudStatusJob($site->id))->handle();

    expect($site->fresh()->status)->toBe(Site::STATUS_CONTAINER_PROVISIONING);
});
test('backend inspect failure records error without status change', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/app-12345' => Http::response([], 503),
    ]);
    $site = makeProvisioningSite();

    (new PollCloudStatusJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_PROVISIONING);
    expect($fresh->meta['container']['last_poll_error'])->not->toBeEmpty();
});
test('non container site is no op', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Php,
    ]);
    $originalStatus = $site->status;

    (new PollCloudStatusJob($site->id))->handle();

    expect($site->fresh()->status)->toBe($originalStatus);
});
function makeProvisioningSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
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
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_backend_id' => 'app-12345',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
    ]);
}
