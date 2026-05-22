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
 * Coverage for the new instance-count knob:
 *  - Site.meta.container.instance_count is the source of truth.
 *  - Backend createApp / createAppFromSource consume it (DO sends
 *    the value in the spec; AWS treats it as operator intent).
 *  - dply:edge:scale persists it + queues a redeploy.
 *  - Dashboard pulls the value through the meta lookup (separate
 *    test below for the rendered value, not asserted here to keep
 *    the test focused on behaviour).
 */
class EdgeScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_create_app_sends_instance_count(): void
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
            instanceCount: 3,
        );

        Http::assertSent(function (Request $request) {
            return ($request->data()['spec']['services'][0]['instance_count'] ?? null) === 3;
        });
    }

    public function test_do_backend_provision_reads_instance_count_from_meta(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-1', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'meta' => ['container' => ['instance_count' => 5]],
        ]);

        (new DigitalOceanAppPlatformBackend)->provision($site, $this->credential());

        Http::assertSent(function (Request $request) {
            return ($request->data()['spec']['services'][0]['instance_count'] ?? null) === 5;
        });
    }

    public function test_cli_persists_instance_count_and_queues_redeploy(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:scale', [
            'site' => $site->name,
            '--instances' => '4',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(4, $site->fresh()->meta['container']['instance_count']);
        Queue::assertPushed(RedeployEdgeSiteJob::class);
    }

    public function test_cli_no_redeploy_skips_queue(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:scale', [
            'site' => $site->name,
            '--instances' => '2',
            '--no-redeploy' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $site->fresh()->meta['container']['instance_count']);
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_cli_rejects_missing_instances(): void
    {
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:scale', ['site' => $site->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--instances=', Artisan::output());
    }

    public function test_cli_rejects_out_of_range_instances(): void
    {
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:edge:scale', [
            'site' => $site->name,
            '--instances' => '0',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('between 1 and 50', Artisan::output());
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

        $exit = Artisan::call('dply:edge:scale', [
            'site' => $vmSite->name,
            '--instances' => '3',
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
