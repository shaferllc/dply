<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Jobs\TeardownEdgeSiteJob;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end coverage for the per-branch preview-deploy flow:
 *  - CreateEdgePreviewSite spawns a child Site, copies env / port /
 *    backend / region, links via meta.container.preview_parent_site_id,
 *    and is idempotent on re-call (no duplicates per parent + branch).
 *  - dply:edge:preview:create / :teardown / :list cover the CI surface.
 *  - The container-dashboard partial renders a "Preview deployments"
 *    list on the parent site when previews exist.
 */
class EdgePreviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_spawns_preview_with_parent_metadata(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $preview = (new CreateEdgePreviewSite)->handle($parent, 'feature/login', prNumber: 42);

        $this->assertNotSame($parent->id, $preview->id);
        $this->assertSame($parent->id, $preview->meta['container']['preview_parent_site_id']);
        $this->assertSame('feature/login', $preview->meta['container']['preview_branch']);
        $this->assertSame(42, $preview->meta['container']['preview_pr_number']);
        $this->assertSame('acme/api', $preview->meta['container']['source']['repo']);
        $this->assertSame('feature/login', $preview->meta['container']['source']['branch']);
        $this->assertSame($parent->container_backend, $preview->container_backend);
        $this->assertSame($parent->container_region, $preview->container_region);
        $this->assertSame($parent->container_port, $preview->container_port);
        $this->assertSame($parent->env_file_content, $preview->env_file_content);
        $this->assertSame(SiteType::Container, $preview->type);
        $this->assertNull($preview->container_image);
        $this->assertStringStartsWith('pr-42-', $preview->slug);

        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_action_is_idempotent_on_repeat_branch(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $first = (new CreateEdgePreviewSite)->handle($parent, 'feature/x');
        $second = (new CreateEdgePreviewSite)->handle($parent, 'feature/x');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Site::query()
            ->whereJsonContains('meta->container->preview_parent_site_id', $parent->id)
            ->count());
        // Only the first call dispatches a provision job; the second
        // returns the existing preview without re-queueing.
        Queue::assertPushed(ProvisionEdgeSiteJob::class, 1);
    }

    public function test_action_rejects_non_source_parent(): void
    {
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        $parent->update(['meta' => ['container' => []]]); // strip source spec

        $this->expectException(\RuntimeException::class);
        (new CreateEdgePreviewSite)->handle($parent->fresh(), 'feature/x');
    }

    public function test_torn_down_preview_does_not_block_new_create(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $first = (new CreateEdgePreviewSite)->handle($parent, 'feature/x');
        // Simulate the teardown job marking it dead.
        $first->update([
            'meta' => array_merge($first->meta, [
                'container' => array_merge($first->meta['container'] ?? [], [
                    'torn_down_at' => now()->toIso8601String(),
                ]),
            ]),
        ]);

        $second = (new CreateEdgePreviewSite)->handle($parent, 'feature/x');

        // The new site is fresh, not the torn-down one.
        $this->assertNotSame($first->id, $second->id);
        // findExisting returns the live preview, not the torn-down one.
        $this->assertSame($second->id, CreateEdgePreviewSite::findExisting($parent, 'feature/x')?->id);
        // listForParent only counts live previews.
        $this->assertCount(1, CreateEdgePreviewSite::listForParent($parent));
    }

    public function test_action_uses_branch_slug_when_no_pr_number(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $preview = (new CreateEdgePreviewSite)->handle($parent, 'feature/login-form');

        $this->assertStringStartsWith('preview-feature-login-form-', $preview->slug);
        $this->assertNull($preview->meta['container']['preview_pr_number']);
    }

    public function test_create_command_spawns_and_reports(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $exit = Artisan::call('dply:edge:preview:create', [
            'parent' => $parent->name,
            '--branch' => 'feature/x',
            '--pr' => '7',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Preview ready', Artisan::output());
        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_create_command_requires_branch(): void
    {
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $exit = Artisan::call('dply:edge:preview:create', ['parent' => $parent->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--branch is required', Artisan::output());
    }

    public function test_teardown_command_queues_teardown_for_existing_preview(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        (new CreateEdgePreviewSite)->handle($parent, 'feature/x');

        $exit = Artisan::call('dply:edge:preview:teardown', [
            'parent' => $parent->name,
            '--branch' => 'feature/x',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('teardown queued', Artisan::output());
        Queue::assertPushed(TeardownEdgeSiteJob::class);
    }

    public function test_teardown_command_is_idempotent_when_branch_unknown(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $exit = Artisan::call('dply:edge:preview:teardown', [
            'parent' => $parent->name,
            '--branch' => 'never-existed',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already torn down', Artisan::output());
        Queue::assertNotPushed(TeardownEdgeSiteJob::class);
    }

    public function test_list_command_emits_branch_and_pr_in_json(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        (new CreateEdgePreviewSite)->handle($parent, 'feature/login', prNumber: 42);
        (new CreateEdgePreviewSite)->handle($parent, 'feature/signup', prNumber: 43);

        Artisan::call('dply:edge:preview:list', [
            'parent' => $parent->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $payload['total']);
        $branches = array_column($payload['previews'], 'branch');
        $this->assertContains('feature/login', $branches);
        $this->assertContains('feature/signup', $branches);
    }

    public function test_dashboard_teardown_button_dispatches_teardown_job(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        $preview = (new CreateEdgePreviewSite)->handle($parent, 'feature/x', prNumber: 7);

        Livewire::actingAs($user)
            ->test(Settings::class, [
                'server' => $parent->server,
                'site' => $parent,
                'section' => 'general',
            ])
            ->call('tearDownContainerPreview', $preview->id)
            ->assertHasNoErrors();

        Queue::assertPushed(TeardownEdgeSiteJob::class, fn ($j) => $j->siteId === $preview->id);
    }

    public function test_dashboard_teardown_rejects_unrelated_site(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        $orphan = $this->makeSourceParent($user, $org); // different parent — not a child of $parent

        Livewire::actingAs($user)
            ->test(Settings::class, [
                'server' => $parent->server,
                'site' => $parent,
                'section' => 'general',
            ])
            ->call('tearDownContainerPreview', $orphan->id);

        Queue::assertNotPushed(TeardownEdgeSiteJob::class);
    }

    public function test_dashboard_renders_github_webhook_section_for_source_sites(): void
    {
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $parent->server,
            'site' => $parent,
        ]));

        $response->assertOk()
            ->assertSee('GitHub webhook')
            ->assertSee(route('hooks.edge.github', $parent), false)
            ->assertSee('Pull requests');
    }

    public function test_dashboard_renders_preview_deployments_panel(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $parent = $this->makeSourceParent($user, $org);
        (new CreateEdgePreviewSite)->handle($parent, 'feature/dashboard', prNumber: 99);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $parent->server,
            'site' => $parent,
        ]));

        $response->assertOk()
            ->assertSee('Preview deployments')
            ->assertSee('PR #99')
            ->assertSee('feature/dashboard');
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return [$user, $org];
    }

    private function makeSourceParent(User $user, Organization $org): Site
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
            'name' => 'API service',
            'slug' => 'api-service',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'env_file_content' => "APP_ENV=production\n",
            'status' => Site::STATUS_CONTAINER_ACTIVE,
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
    }
}
