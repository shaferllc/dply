<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CloudCreatePendingFlushTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\AttachCloudDatabaseJob;
use App\Modules\Cloud\Jobs\AttachCloudDomainJob;
use App\Jobs\PollCloudStatusJob;
use App\Modules\Cloud\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: ProviderCredential, 3: Server}
 */
function pendingFlushFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['api_token' => 'tok'],
    ]);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD, 'edge' => ['backend' => 'digitalocean_app_platform']],
    ]);

    return [$user, $org, $credential, $server];
}

test('PollCloudStatusJob fans out pending domain attachments on active transition', function () {
    Bus::fake([AttachCloudDomainJob::class]);
    [$user, $org, $credential, $server] = pendingFlushFixture();

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
        'container_backend_id' => 'app-flush',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
        'meta' => ['container' => ['pending_domains' => ['app.example.com', 'www.example.com']]],
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-flush' => Http::response([
            'app' => [
                'id' => 'app-flush',
                'phase' => 'ACTIVE',
                'default_ingress' => 'https://acme.ondigitalocean.app',
                'spec' => ['name' => 'acme'],
            ],
        ], 200),
    ]);

    (new PollCloudStatusJob($site->id))->handle();

    Bus::assertDispatched(AttachCloudDomainJob::class, fn ($job) => $job->siteId === $site->id && $job->hostname === 'app.example.com');
    Bus::assertDispatched(AttachCloudDomainJob::class, fn ($job) => $job->siteId === $site->id && $job->hostname === 'www.example.com');

    $fresh = $site->fresh();
    expect($fresh->status)->toBe(Site::STATUS_CONTAINER_ACTIVE);
    expect($fresh->meta['container']['pending_domains'] ?? null)->toBeNull();
});

test('PollCloudStatusJob does not re-dispatch when already active', function () {
    Bus::fake([AttachCloudDomainJob::class]);
    [$user, $org, $credential, $server] = pendingFlushFixture();

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
        'container_backend_id' => 'app-stable',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => ['container' => ['pending_domains' => ['lingering.example.com']]],
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-stable' => Http::response([
            'app' => [
                'id' => 'app-stable',
                'phase' => 'ACTIVE',
                'default_ingress' => 'https://x.ondigitalocean.app',
                'spec' => ['name' => 'x'],
            ],
        ], 200),
    ]);

    (new PollCloudStatusJob($site->id))->handle();

    Bus::assertNotDispatched(AttachCloudDomainJob::class);
    // Lingering pending list stays put because we only flush on the transition.
    expect($site->fresh()->meta['container']['pending_domains'] ?? null)->toBe(['lingering.example.com']);
});

test('PollCloudStatusJob skips domain flush when still building', function () {
    Bus::fake([AttachCloudDomainJob::class]);
    [$user, $org, $credential, $server] = pendingFlushFixture();

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
        'container_backend_id' => 'app-build',
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
        'meta' => ['container' => ['pending_domains' => ['will-wait.example.com']]],
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-build' => Http::response([
            'app' => ['id' => 'app-build', 'phase' => 'BUILDING'],
        ], 200),
    ]);

    (new PollCloudStatusJob($site->id))->handle();

    Bus::assertNotDispatched(AttachCloudDomainJob::class);
    expect($site->fresh()->meta['container']['pending_domains'] ?? null)->toBe(['will-wait.example.com']);
});

test('ProvisionCloudDatabaseJob fans out AttachCloudDatabaseJob to every pivoted site on activation', function () {
    Bus::fake([AttachCloudDatabaseJob::class]);
    [$user, $org, $credential, $server] = pendingFlushFixture();

    $db = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => CloudDatabase::STATUS_PROVISIONING,
        'backend_id' => 'do-db-fanout',
        'connection' => null,
    ]);

    foreach (['a', 'b'] as $slug) {
        $pivoted = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'slug' => 'site-'.$slug,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_PROVISIONING,
        ]);
        $db->sites()->attach($pivoted->id);
    }

    Http::fake([
        'api.digitalocean.com/v2/databases/do-db-fanout' => Http::response([
            'database' => [
                'id' => 'do-db-fanout',
                'status' => 'online',
                'connection' => [
                    'host' => 'db.example.com',
                    'port' => 25060,
                    'user' => 'doadmin',
                    'password' => 'secret',
                    'database' => 'defaultdb',
                    'ssl' => true,
                ],
            ],
        ], 200),
    ]);

    (new ProvisionCloudDatabaseJob($db->id))->handle();

    expect($db->fresh()->status)->toBe(CloudDatabase::STATUS_ACTIVE);
    Bus::assertDispatchedTimes(AttachCloudDatabaseJob::class, 2);
});

test('ProvisionCloudDatabaseJob does not fan out when DB is still provisioning', function () {
    Bus::fake([AttachCloudDatabaseJob::class]);
    [$user, $org, $credential, $server] = pendingFlushFixture();

    $db = CloudDatabase::factory()->create([
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => CloudDatabase::STATUS_PROVISIONING,
        'backend_id' => 'do-db-pending',
        'connection' => null,
    ]);

    $pivoted = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'container_backend' => 'digitalocean_app_platform',
        'container_port' => 8080,
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
    ]);
    $db->sites()->attach($pivoted->id);

    Http::fake([
        'api.digitalocean.com/v2/databases/do-db-pending' => Http::response([
            'database' => ['id' => 'do-db-pending', 'status' => 'creating'],
        ], 200),
    ]);

    (new ProvisionCloudDatabaseJob($db->id))->handle();

    expect($db->fresh()->status)->toBe(CloudDatabase::STATUS_PROVISIONING);
    Bus::assertNotDispatched(AttachCloudDatabaseJob::class);
});
