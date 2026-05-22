<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cloud\CreateCloudPreviewSite;
use App\Enums\SiteType;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudTeardownCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_teardown_for_a_simple_site(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:teardown', ['site' => $site->name]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Teardown queued', Artisan::output());
        Queue::assertPushed(TeardownCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_refuses_when_previews_exist_without_flag(): void
    {
        Queue::fake();
        $parent = $this->makeContainerSite([
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
            'container_image' => null,
        ]);
        (new CreateCloudPreviewSite)->handle($parent, 'feature/x');

        $exit = Artisan::call('dply:cloud:teardown', ['site' => $parent->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('live preview', Artisan::output());
        // The parent's teardown should NOT have been queued. Preview
        // creation queues a Provision job, so we filter by class.
        Queue::assertNotPushed(TeardownCloudSiteJob::class);
    }

    public function test_with_previews_flag_tears_down_each_preview_then_parent(): void
    {
        Queue::fake();
        $parent = $this->makeContainerSite([
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
            'container_image' => null,
        ]);
        $preview1 = (new CreateCloudPreviewSite)->handle($parent, 'feature/x', prNumber: 7);
        $preview2 = (new CreateCloudPreviewSite)->handle($parent, 'feature/y', prNumber: 9);

        $exit = Artisan::call('dply:cloud:teardown', [
            'site' => $parent->name,
            '--with-previews' => true,
        ]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(TeardownCloudSiteJob::class, 3); // both previews + parent
        Queue::assertPushed(TeardownCloudSiteJob::class, fn ($j) => $j->siteId === $parent->id);
        Queue::assertPushed(TeardownCloudSiteJob::class, fn ($j) => $j->siteId === $preview1->id);
        Queue::assertPushed(TeardownCloudSiteJob::class, fn ($j) => $j->siteId === $preview2->id);
    }

    public function test_rejects_non_cloud_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:cloud:teardown', ['site' => $vmSite->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a cloud container site', Artisan::output());
    }

    public function test_missing_site(): void
    {
        $exit = Artisan::call('dply:cloud:teardown', ['site' => 'nope']);

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
}
