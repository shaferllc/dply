<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EdgeRedeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_redeploy_for_image_mode_site(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite(['container_image' => 'app:v1']);

        $exit = Artisan::call('dply:edge:redeploy', ['site' => $site->name]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Redeploy queued', Artisan::output());
        Queue::assertPushed(RedeployEdgeSiteJob::class, fn ($j) => $j->siteId === $site->id && $j->newImage === null);
    }

    public function test_dispatches_redeploy_for_source_mode_site(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite([
            'container_image' => null,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);

        $exit = Artisan::call('dply:edge:redeploy', ['site' => $site->name]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(RedeployEdgeSiteJob::class, fn ($j) => $j->siteId === $site->id);
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

        $exit = Artisan::call('dply:edge:redeploy', ['site' => $vmSite->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not an edge container site', Artisan::output());
    }

    public function test_missing_site_returns_failure(): void
    {
        $exit = Artisan::call('dply:edge:redeploy', ['site' => 'nope']);

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
