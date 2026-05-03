<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EdgeDeploymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_do_deployments_via_http_fake(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments?per_page=10' => Http::response([
                'deployments' => [
                    ['id' => 'dep-3', 'phase' => 'ACTIVE', 'created_at' => '2026-05-03T10:00:00Z', 'updated_at' => '2026-05-03T10:03:00Z', 'cause_details' => ['type' => 'COMMIT_PUSH']],
                    ['id' => 'dep-2', 'phase' => 'SUPERSEDED', 'created_at' => '2026-05-03T08:00:00Z', 'updated_at' => '2026-05-03T08:04:00Z', 'cause_details' => ['type' => 'MANUAL']],
                ],
            ], 200),
        ]);

        $site = $this->makeContainerSite(['container_backend_id' => 'do-app-1']);
        ProviderCredential::query()->create([
            'user_id' => $site->user_id,
            'organization_id' => $site->organization_id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Artisan::call('dply:edge:deployments', ['site' => $site->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $payload['total']);
        $this->assertSame('dep-3', $payload['deployments'][0]['id']);
        $this->assertSame('ACTIVE', $payload['deployments'][0]['phase']);
        $this->assertSame('COMMIT_PUSH', $payload['deployments'][0]['cause']);
    }

    public function test_fake_cloud_returns_synthetic_entries(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        Artisan::call('dply:edge:deployments', ['site' => $site->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertGreaterThanOrEqual(1, $payload['total']);
        $this->assertSame('ACTIVE', $payload['deployments'][0]['phase']);
        $this->assertStringStartsWith('fake-dep-', $payload['deployments'][0]['id']);
    }

    public function test_limit_clamps_to_100(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments*' => Http::response([
                'deployments' => [],
            ], 200),
        ]);
        $site = $this->makeContainerSite(['container_backend_id' => 'do-app-1']);
        ProviderCredential::query()->create([
            'user_id' => $site->user_id,
            'organization_id' => $site->organization_id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Artisan::call('dply:edge:deployments', ['site' => $site->name, '--limit' => '999']);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'per_page=100');
        });
    }

    public function test_human_output_renders_table_when_present(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        Artisan::call('dply:edge:deployments', ['site' => $site->name]);
        $output = Artisan::output();

        $this->assertStringContainsString('Recent deployments', $output);
        $this->assertStringContainsString('fake-dep-', $output);
    }

    public function test_human_output_empty_state(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments*' => Http::response([
                'deployments' => [],
            ], 200),
        ]);
        $site = $this->makeContainerSite(['container_backend_id' => 'do-app-1']);
        ProviderCredential::query()->create([
            'user_id' => $site->user_id,
            'organization_id' => $site->organization_id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        Artisan::call('dply:edge:deployments', ['site' => $site->name]);
        $this->assertStringContainsString('No deployments yet', Artisan::output());
    }

    public function test_rejects_non_edge_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:edge:deployments', ['site' => $vmSite->name]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not an edge container site', Artisan::output());
    }

    public function test_missing_site(): void
    {
        $exit = Artisan::call('dply:edge:deployments', ['site' => 'nope']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', Artisan::output());
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
}
