<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CloudImageRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_redeploy_appends_to_image_history(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345/deployments' => Http::response([
                'deployment' => ['id' => 'dep-1'],
            ], 201),
        ]);
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v1']);

        (new RedeployCloudSiteJob($site->id))->handle();

        $history = $site->fresh()->meta['container']['image_history'] ?? [];
        $this->assertCount(1, $history);
        $this->assertSame('ghcr.io/acme/api:v1', $history[0]['image']);
        $this->assertSame('dep-1', $history[0]['deployment_id']);
    }

    public function test_redeploy_with_new_image_records_new_image_in_history(): void
    {
        Http::fake([
            // PUT update spec
            'api.digitalocean.com/v2/apps/app-12345' => Http::sequence()
                ->push(['app' => ['id' => 'app-12345', 'spec' => ['services' => [['name' => 'web']]]]], 200)
                ->push([], 200),
            'api.digitalocean.com/v2/apps/app-12345/deployments' => Http::response([
                'deployment' => ['id' => 'dep-2'],
            ], 201),
        ]);
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v1']);

        (new RedeployCloudSiteJob($site->id, 'ghcr.io/acme/api:v2'))->handle();

        $fresh = $site->fresh();
        $this->assertSame('ghcr.io/acme/api:v2', $fresh->container_image);
        $history = $fresh->meta['container']['image_history'] ?? [];
        $this->assertSame('ghcr.io/acme/api:v2', end($history)['image']);
    }

    public function test_history_caps_at_ten_entries(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/app-12345*' => Http::response(['deployment' => ['id' => 'dep']], 201),
        ]);
        $existing = [];
        for ($i = 1; $i <= 10; $i++) {
            $existing[] = ['image' => "ghcr.io/acme/api:v{$i}", 'deployed_at' => now()->subDays(11 - $i)->toIso8601String()];
        }
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v10', $existing]);

        (new RedeployCloudSiteJob($site->id))->handle();

        $history = $site->fresh()->meta['container']['image_history'] ?? [];
        $this->assertCount(10, $history);
        // Oldest entry should now be v2 (v1 was bumped off the array).
        $this->assertSame('ghcr.io/acme/api:v2', $history[0]['image']);
    }

    public function test_rollback_method_dispatches_redeploy_with_old_image(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v3', [
            ['image' => 'ghcr.io/acme/api:v1', 'deployed_at' => now()->subDays(2)->toIso8601String()],
            ['image' => 'ghcr.io/acme/api:v2', 'deployed_at' => now()->subDays(1)->toIso8601String()],
            ['image' => 'ghcr.io/acme/api:v3', 'deployed_at' => now()->toIso8601String()],
        ]]);

        Livewire::actingAs($site->user)
            ->test(SiteSettings::class, ['server' => $site->server, 'site' => $site, 'section' => 'general'])
            ->call('rollbackContainerImage', 'ghcr.io/acme/api:v1');

        Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id && $job->newImage === 'ghcr.io/acme/api:v1';
        });
    }

    public function test_rollback_to_current_image_is_no_op_with_warning_toast(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v1']);

        Livewire::actingAs($site->user)
            ->test(SiteSettings::class, ['server' => $site->server, 'site' => $site, 'section' => 'general'])
            ->call('rollbackContainerImage', 'ghcr.io/acme/api:v1')
            ->assertDispatched('notify');

        Queue::assertNotPushed(RedeployCloudSiteJob::class);
    }

    public function test_dashboard_renders_image_history_with_current_marker(): void
    {
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v3', [
            ['image' => 'ghcr.io/acme/api:v1', 'deployed_at' => now()->subDays(2)->toIso8601String()],
            ['image' => 'ghcr.io/acme/api:v2', 'deployed_at' => now()->subDays(1)->toIso8601String()],
            ['image' => 'ghcr.io/acme/api:v3', 'deployed_at' => now()->toIso8601String()],
        ]]);

        $response = $this->actingAs($site->user)->get(route('sites.show', ['server' => $site->server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Image history')
            ->assertSee('ghcr.io/acme/api:v1')
            ->assertSee('ghcr.io/acme/api:v3')
            ->assertSee('Current');
    }

    public function test_history_panel_hidden_when_only_one_entry(): void
    {
        $site = $this->makeContainerSite(['ghcr.io/acme/api:v1', [
            ['image' => 'ghcr.io/acme/api:v1', 'deployed_at' => now()->toIso8601String()],
        ]]);

        $response = $this->actingAs($site->user)->get(route('sites.show', ['server' => $site->server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Image history');
    }

    /**
     * @param  array{0: string, 1?: array<int, array<string, mixed>>}  $imageState  [current_image, optional pre-existing history]
     */
    private function makeContainerSite(array $imageState): Site
    {
        [$image, $history] = [$imageState[0], $imageState[1] ?? []];

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => $image,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_backend_id' => 'app-12345',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => $history === [] ? null : ['container' => ['image_history' => $history]],
        ]);
    }
}
