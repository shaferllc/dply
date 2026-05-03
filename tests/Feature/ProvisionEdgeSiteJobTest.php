<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionEdgeSiteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_via_do_app_platform_and_persists_backend_id(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => [
                    'id' => 'do-app-12345',
                    'default_ingress' => 'https://acme-api.ondigitalocean.app',
                ],
            ], 201),
        ]);

        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $site = $this->makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

        (new ProvisionEdgeSiteJob($site->id))->handle();

        $fresh = $site->fresh();
        $this->assertSame('do-app-12345', $fresh->container_backend_id);
        $this->assertSame(Site::STATUS_CONTAINER_ACTIVE, $fresh->status);
        $this->assertSame('https://acme-api.ondigitalocean.app', $fresh->meta['container']['live_url']);
    }

    public function test_marks_failed_on_backend_error(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response(['message' => 'invalid spec'], 422),
        ]);

        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

        try {
            (new ProvisionEdgeSiteJob($site->id))->handle();
            $this->fail('Expected exception');
        } catch (\Throwable) {
            // expected — job rethrows so the queue can retry
        }

        $this->assertSame(Site::STATUS_CONTAINER_FAILED, $site->fresh()->status);
        $this->assertNotEmpty($site->fresh()->meta['container']['last_error']);
    }

    public function test_no_credential_marks_failed_without_throwing(): void
    {
        [$user, $org] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, 'digitalocean_app_platform', 'nyc');

        (new ProvisionEdgeSiteJob($site->id))->handle();

        $this->assertSame(Site::STATUS_CONTAINER_FAILED, $site->fresh()->status);
    }

    public function test_missing_site_is_no_op(): void
    {
        (new ProvisionEdgeSiteJob('01nope0000000000000000nope'))->handle();
        $this->assertTrue(true); // no exception
    }

    public function test_source_mode_calls_create_app_from_source(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => [
                    'id' => 'do-app-src-1',
                    'default_ingress' => 'https://src.ondigitalocean.app',
                ],
            ], 201),
        ]);

        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_PENDING,
            'meta' => [
                'container' => [
                    'source' => [
                        'repo' => 'acme/api',
                        'branch' => 'main',
                        'deploy_on_push' => true,
                    ],
                ],
            ],
        ]);

        (new ProvisionEdgeSiteJob($site->id))->handle();

        $fresh = $site->fresh();
        $this->assertSame('do-app-src-1', $fresh->container_backend_id);
        $this->assertSame(Site::STATUS_CONTAINER_ACTIVE, $fresh->status);

        Http::assertSent(function ($req) {
            $svc = $req->data()['spec']['services'][0] ?? [];

            return ($svc['github']['repo'] ?? null) === 'acme/api'
                && ($svc['github']['branch'] ?? null) === 'main'
                && ($svc['github']['deploy_on_push'] ?? null) === true;
        });
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function makeContainerSite(User $user, Organization $org, string $backend, string $region): Site
    {
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1.27',
            'container_port' => 80,
            'container_backend' => $backend,
            'container_region' => $region,
            'status' => Site::STATUS_PENDING,
        ]);
    }
}
