<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\DigitalOceanAppPlatformService;
use App\Services\Edge\DigitalOceanAppPlatformBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage for the new compute-tier knob:
 *  - Site.meta.container.size_tier is the source of truth.
 *  - DO backend maps small/medium/large/xlarge → basic-xxs/basic-xs/basic-s/basic-m
 *    and emits the slug in the spec.
 *  - dply:edge:resize persists the tier + queues a redeploy.
 */
class EdgeResizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_backend_provision_maps_size_tier_to_slug(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-1', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'meta' => ['container' => ['size_tier' => 'large']],
        ]);

        (new DigitalOceanAppPlatformBackend)->provision($site, $this->credential());

        Http::assertSent(function (Request $request) {
            return ($request->data()['spec']['services'][0]['instance_size_slug'] ?? null) === 'basic-s';
        });
    }

    public function test_do_service_create_app_uses_passed_size_slug(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-1', 'default_ingress' => null],
            ], 201),
        ]);

        $service = new DigitalOceanAppPlatformService($this->credential());
        $service->createApp(
            appName: 'svc',
            region: 'nyc',
            image: 'nginx:1',
            port: 80,
            instanceSizeSlug: 'professional-xs',
        );

        Http::assertSent(function (Request $request) {
            return ($request->data()['spec']['services'][0]['instance_size_slug'] ?? null) === 'professional-xs';
        });
    }

    public function test_cli_resize_persists_tier_and_queues_redeploy(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:resize', [
            'site' => $site->name,
            '--size' => 'medium',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('medium', $site->fresh()->meta['container']['size_tier']);
        Queue::assertPushed(RedeployEdgeSiteJob::class);
    }

    public function test_cli_no_redeploy_skips_queue(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        Artisan::call('dply:edge:resize', [
            'site' => $site->name,
            '--size' => 'xlarge',
            '--no-redeploy' => true,
        ]);

        $this->assertSame('xlarge', $site->fresh()->meta['container']['size_tier']);
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_cli_rejects_unknown_tier(): void
    {
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:resize', [
            'site' => $site->name,
            '--size' => 'jumbo',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Valid:', Artisan::output());
    }

    public function test_cli_rejects_missing_size(): void
    {
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:resize', ['site' => $site->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--size=', Artisan::output());
    }

    public function test_cli_rejects_non_edge_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:edge:resize', [
            'site' => $vmSite->name,
            '--size' => 'small',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not an edge container site', Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(array $overrides = []): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
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

    private function credential(): ProviderCredential
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        return ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'Test',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);
    }
}
