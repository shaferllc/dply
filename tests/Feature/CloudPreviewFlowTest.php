<?php

declare(strict_types=1);

namespace Tests\Feature\CloudPreviewFlowTest;

use App\Modules\Cloud\Actions\CreateCloudPreviewSite;
use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\ProvisionCloudSiteJob;
use App\Modules\Cloud\Jobs\TeardownCloudSiteJob;
use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

test('action spawns preview with parent metadata', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $preview = (new CreateCloudPreviewSite)->handle($parent, 'feature/login', prNumber: 42);

    $this->assertNotSame($parent->id, $preview->id);
    expect($preview->meta['container']['preview_parent_site_id'])->toBe($parent->id);
    expect($preview->meta['container']['preview_branch'])->toBe('feature/login');
    expect($preview->meta['container']['preview_pr_number'])->toBe(42);
    expect($preview->meta['container']['source']['repo'])->toBe('acme/api');
    expect($preview->meta['container']['source']['branch'])->toBe('feature/login');
    expect($preview->container_backend)->toBe($parent->container_backend);
    expect($preview->container_region)->toBe($parent->container_region);
    expect($preview->container_port)->toBe($parent->container_port);
    expect($preview->env_file_content)->toBe($parent->env_file_content);
    expect($preview->type)->toBe(SiteType::Container);
    expect($preview->container_image)->toBeNull();
    expect($preview->slug)->toStartWith('pr-42-');

    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
test('action is idempotent on repeat branch', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $first = (new CreateCloudPreviewSite)->handle($parent, 'feature/x');
    $second = (new CreateCloudPreviewSite)->handle($parent, 'feature/x');

    expect($second->id)->toBe($first->id);
    expect(Site::query()
        ->whereJsonContains('meta->container->preview_parent_site_id', $parent->id)
        ->count())->toBe(1);

    // Only the first call dispatches a provision job; the second
    // returns the existing preview without re-queueing.
    Queue::assertPushed(ProvisionCloudSiteJob::class, 1);
});
test('action rejects non source parent', function () {
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    $parent->update(['meta' => ['container' => []]]);

    // strip source spec
    $this->expectException(\RuntimeException::class);
    (new CreateCloudPreviewSite)->handle($parent->fresh(), 'feature/x');
});
test('torn down preview does not block new create', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $first = (new CreateCloudPreviewSite)->handle($parent, 'feature/x');

    // Simulate the teardown job marking it dead.
    $first->update([
        'meta' => array_merge($first->meta, [
            'container' => array_merge($first->meta['container'] ?? [], [
                'torn_down_at' => now()->toIso8601String(),
            ]),
        ]),
    ]);

    $second = (new CreateCloudPreviewSite)->handle($parent, 'feature/x');

    // The new site is fresh, not the torn-down one.
    $this->assertNotSame($first->id, $second->id);

    // findExisting returns the live preview, not the torn-down one.
    expect(CreateCloudPreviewSite::findExisting($parent, 'feature/x')?->id)->toBe($second->id);

    // listForParent only counts live previews.
    expect(CreateCloudPreviewSite::listForParent($parent))->toHaveCount(1);
});
test('action uses branch slug when no pr number', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $preview = (new CreateCloudPreviewSite)->handle($parent, 'feature/login-form');

    expect($preview->slug)->toStartWith('preview-feature-login-form-');
    expect($preview->meta['container']['preview_pr_number'])->toBeNull();
});
test('create command spawns and reports', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $exit = Artisan::call('dply:cloud:preview:create', [
        'parent' => $parent->name,
        '--branch' => 'feature/x',
        '--pr' => '7',
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Preview ready', Artisan::output());
    Queue::assertPushed(ProvisionCloudSiteJob::class);
});
test('create command requires branch', function () {
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $exit = Artisan::call('dply:cloud:preview:create', ['parent' => $parent->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--branch is required', Artisan::output());
});
test('teardown command queues teardown for existing preview', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    (new CreateCloudPreviewSite)->handle($parent, 'feature/x');

    $exit = Artisan::call('dply:cloud:preview:teardown', [
        'parent' => $parent->name,
        '--branch' => 'feature/x',
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('teardown queued', Artisan::output());
    Queue::assertPushed(TeardownCloudSiteJob::class);
});
test('teardown command is idempotent when branch unknown', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $exit = Artisan::call('dply:cloud:preview:teardown', [
        'parent' => $parent->name,
        '--branch' => 'never-existed',
    ]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('already torn down', Artisan::output());
    Queue::assertNotPushed(TeardownCloudSiteJob::class);
});
test('list command emits branch and pr in json', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    (new CreateCloudPreviewSite)->handle($parent, 'feature/login', prNumber: 42);
    (new CreateCloudPreviewSite)->handle($parent, 'feature/signup', prNumber: 43);

    Artisan::call('dply:cloud:preview:list', [
        'parent' => $parent->name,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['total'])->toBe(2);
    $branches = array_column($payload['previews'], 'branch');
    expect($branches)->toContain('feature/login');
    expect($branches)->toContain('feature/signup');
});
test('dashboard teardown button dispatches teardown job', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    $preview = (new CreateCloudPreviewSite)->handle($parent, 'feature/x', prNumber: 7);

    Livewire::actingAs($user)
        ->test(Settings::class, [
            'server' => $parent->server,
            'site' => $parent,
            'section' => 'general',
        ])
        ->call('tearDownContainerPreview', $preview->id)
        ->assertHasNoErrors();

    Queue::assertPushed(TeardownCloudSiteJob::class, fn ($j) => $j->siteId === $preview->id);
});
test('dashboard teardown rejects unrelated site', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    $orphan = makeSourceParent($user, $org);

    // different parent — not a child of $parent
    Livewire::actingAs($user)
        ->test(Settings::class, [
            'server' => $parent->server,
            'site' => $parent,
            'section' => 'general',
        ])
        ->call('tearDownContainerPreview', $orphan->id);

    Queue::assertNotPushed(TeardownCloudSiteJob::class);
});
test('dashboard renders github webhook section for source sites', function () {
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);

    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $parent->server,
        'site' => $parent,
    ]));

    $response->assertOk()
        ->assertSee('GitHub webhook')
        ->assertSee(route('hooks.cloud.github', $parent), false)
        ->assertSee('Pull requests');
});
test('dashboard renders preview deployments panel', function () {
    Queue::fake();
    [$user, $org] = scaffold();
    $parent = makeSourceParent($user, $org);
    (new CreateCloudPreviewSite)->handle($parent, 'feature/dashboard', prNumber: 99);

    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $parent->server,
        'site' => $parent,
    ]));

    $response->assertOk()
        ->assertSee('Preview deployments')
        ->assertSee('PR #99')
        ->assertSee('feature/dashboard');
});
/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
function makeSourceParent(User $user, Organization $org): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
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
