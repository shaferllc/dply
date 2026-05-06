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
use App\Services\Edge\DigitalOceanAppPlatformBackend;
use App\Services\Edge\EdgeRouter;
use App\Services\Edge\FakeEdgeBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the fake-cloud edge fallback. With
 * DPLY_FAKE_CLOUD_PROVISION=true and no real ProviderCredential
 * connected for the chosen backend, EdgeRouter swaps the real
 * backend for FakeEdgeBackend so the dev install can drive the
 * full /edge/create flow without DO/AWS keys.
 *
 * Tests run with the fake flag explicitly enabled — phpunit.xml
 * picks up DPLY_FAKE_CLOUD_PROVISION from .env, but other tests
 * disable it; pin it here so this file works on its own.
 */
class FakeEdgeBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['server_provision_fake.env_flag' => true]);
    }

    public function test_router_returns_fake_backend_when_no_real_credential(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

        $backend = EdgeRouter::backendFor($site);

        $this->assertInstanceOf(FakeEdgeBackend::class, $backend);
    }

    public function test_router_returns_real_backend_when_credential_exists(): void
    {
        [$user, $org, $server] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

        $backend = EdgeRouter::backendFor($site);

        $this->assertInstanceOf(DigitalOceanAppPlatformBackend::class, $backend);
    }

    public function test_aws_routes_to_fake_backend_when_no_real_credential(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, $server, 'aws_app_runner');

        $backend = EdgeRouter::backendFor($site);

        $this->assertInstanceOf(FakeEdgeBackend::class, $backend);
    }

    public function test_credential_for_synthesizes_placeholder_in_fake_mode(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, $server, 'digitalocean_app_platform');

        $credential = EdgeRouter::credentialFor($site);

        $this->assertNotNull($credential);
        $this->assertSame($org->id, $credential->organization_id);
        $this->assertSame('digitalocean_app_platform', $credential->provider);
        // Placeholder is not persisted — id will be null/empty.
        $this->assertNull($credential->id);
    }

    public function test_pick_auto_backend_returns_default_in_fake_mode(): void
    {
        $org = Organization::factory()->create();

        $this->assertSame('digitalocean_app_platform', EdgeRouter::pickAutoBackend($org->id));
    }

    public function test_provision_job_brings_site_active_via_fake_backend(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, $server, 'digitalocean_app_platform', 'fake-app');

        (new ProvisionEdgeSiteJob($site->id))->handle();

        $fresh = $site->fresh();
        $this->assertSame(Site::STATUS_CONTAINER_ACTIVE, $fresh->status);
        $this->assertNotEmpty($fresh->container_backend_id);
        $this->assertStringStartsWith('fake-app-', (string) $fresh->container_backend_id);
        $this->assertStringContainsString('.fake-edge.dply.local', (string) $fresh->meta['container']['live_url']);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Server}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return [$user, $org, $server];
    }

    private function makeContainerSite(User $user, Organization $org, Server $server, string $backend, ?string $name = null): Site
    {
        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => $name ?? 'fake-app',
            'slug' => $name ?? 'fake-app',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => $backend,
            'container_region' => 'nyc',
            'status' => Site::STATUS_PENDING,
        ]);
    }
}
