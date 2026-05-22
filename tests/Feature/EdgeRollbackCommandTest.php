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

class EdgeRollbackCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolls_back_one_step_by_default(): void
    {
        Queue::fake();
        $site = $this->makeImageSite('current:v3', [
            ['image' => 'old:v1', 'deployed_at' => '2026-05-01T00:00:00Z'],
            ['image' => 'mid:v2', 'deployed_at' => '2026-05-02T00:00:00Z'],
            ['image' => 'current:v3', 'deployed_at' => '2026-05-03T00:00:00Z'],
        ]);

        $exit = Artisan::call('dply:edge:rollback', ['site' => $site->name]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('mid:v2', Artisan::output());
        Queue::assertPushed(RedeployEdgeSiteJob::class, fn (RedeployEdgeSiteJob $j) => $j->newImage === 'mid:v2');
    }

    public function test_rolls_back_n_steps(): void
    {
        Queue::fake();
        $site = $this->makeImageSite('current:v3', [
            ['image' => 'old:v1', 'deployed_at' => '2026-05-01T00:00:00Z'],
            ['image' => 'mid:v2', 'deployed_at' => '2026-05-02T00:00:00Z'],
            ['image' => 'current:v3', 'deployed_at' => '2026-05-03T00:00:00Z'],
        ]);

        Artisan::call('dply:edge:rollback', ['site' => $site->name, '--steps' => '2']);

        Queue::assertPushed(RedeployEdgeSiteJob::class, fn (RedeployEdgeSiteJob $j) => $j->newImage === 'old:v1');
    }

    public function test_explicit_image_overrides_steps(): void
    {
        Queue::fake();
        $site = $this->makeImageSite('current:v3', []);

        Artisan::call('dply:edge:rollback', ['site' => $site->name, '--image' => 'pinned:v1']);

        Queue::assertPushed(RedeployEdgeSiteJob::class, fn (RedeployEdgeSiteJob $j) => $j->newImage === 'pinned:v1');
    }

    public function test_no_op_when_target_equals_current(): void
    {
        Queue::fake();
        $site = $this->makeImageSite('current:v3', []);

        $exit = Artisan::call('dply:edge:rollback', ['site' => $site->name, '--image' => 'current:v3']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already on', Artisan::output());
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_rejects_when_history_too_short_for_steps(): void
    {
        Queue::fake();
        $site = $this->makeImageSite('current:v3', [
            ['image' => 'current:v3'],
        ]);

        $exit = Artisan::call('dply:edge:rollback', ['site' => $site->name, '--steps' => '5']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot step back', Artisan::output());
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_rejects_source_mode_site(): void
    {
        $site = $this->makeImageSite('—', []);
        $site->update([
            'container_image' => null,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);

        $exit = Artisan::call('dply:edge:rollback', ['site' => $site->name, '--image' => 'whatever']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Source-mode sites have no image history', Artisan::output());
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

        $exit = Artisan::call('dply:edge:rollback', ['site' => $vmSite->name, '--image' => 'whatever']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not an edge container site', Artisan::output());
    }

    public function test_missing_site(): void
    {
        $exit = Artisan::call('dply:edge:rollback', ['site' => 'nope', '--image' => 'whatever']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', Artisan::output());
    }

    /**
     * @param  list<array{image: string, deployed_at?: string}>  $history
     */
    private function makeImageSite(string $current, array $history): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'edge-image-app',
            'slug' => 'edge-image-app',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => $current,
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => 'fake-app-1',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => $history === [] ? [] : ['container' => ['image_history' => $history]],
        ]);
    }
}
