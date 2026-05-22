<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\SyncCloudWorkersJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CloudWorkerDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeContainerSite(string $backend = 'digitalocean_app_platform'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $backend,
            'name' => 'cred',
            'credentials' => ['api_token' => 'tok', 'github_connection_arn' => 'arn:x'],
        ]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => $backend,
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);

        return [$user, $server, $site];
    }

    public function test_workers_section_renders_on_do_site(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Workers & scheduler')
            ->assertSee('Add a queue worker')
            ->assertSee('Enable scheduler');
    }

    public function test_workers_section_lists_existing_workers(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();
        CloudWorker::factory()->create([
            'site_id' => $site->id,
            'name' => 'my-queue-worker',
            'command' => 'php artisan queue:work redis',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('my-queue-worker')
            ->assertSee('php artisan queue:work redis');
    }

    public function test_workers_section_shows_disabled_state_on_app_runner(): void
    {
        [$user, $server, $site] = $this->makeContainerSite('aws_app_runner');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Workers & scheduler')
            ->assertSee('Not available on AWS App Runner')
            ->assertDontSee('Add a queue worker');
    }

    public function test_add_worker_control_creates_worker_and_dispatches_sync(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_worker_command_input', 'php artisan queue:work')
            ->set('container_worker_size_input', 'medium')
            ->set('container_worker_count_input', 2)
            ->call('addContainerWorker');

        $this->assertDatabaseHas('cloud_workers', [
            'site_id' => $site->id,
            'type' => 'worker',
            'size' => 'medium',
            'instance_count' => 2,
        ]);
        Queue::assertPushed(SyncCloudWorkersJob::class);
    }

    public function test_enable_scheduler_control_creates_scheduler(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('enableContainerScheduler');

        $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
        Queue::assertPushed(SyncCloudWorkersJob::class);
    }

    public function test_disable_scheduler_control_removes_scheduler(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();
        $scheduler = CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('disableContainerScheduler');

        $this->assertDatabaseMissing('cloud_workers', ['id' => $scheduler->id]);
        Queue::assertPushed(SyncCloudWorkersJob::class);
    }

    public function test_scale_worker_control_updates_count_and_dispatches_sync(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id, 'instance_count' => 1]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('scaleContainerWorker', $worker->id, 3);

        $this->assertSame(3, $worker->fresh()->instance_count);
        Queue::assertPushed(SyncCloudWorkersJob::class);
    }

    public function test_remove_worker_control_deletes_worker_and_dispatches_sync(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('removeContainerWorker', $worker->id);

        $this->assertDatabaseMissing('cloud_workers', ['id' => $worker->id]);
        Queue::assertPushed(SyncCloudWorkersJob::class);
    }

    public function test_second_scheduler_via_dashboard_is_rejected(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();
        CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('enableContainerScheduler');

        // Still only one scheduler.
        $this->assertSame(1, CloudWorker::query()->where('site_id', $site->id)->where('type', 'scheduler')->count());
    }
}
