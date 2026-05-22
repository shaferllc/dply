<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\PollCloudStatusJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudPollStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_per_provisioning_site(): void
    {
        Queue::fake();
        $a = $this->makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
        $b = $this->makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
        $this->makeContainerSite(Site::STATUS_CONTAINER_ACTIVE); // skipped by default

        $exit = Artisan::call('dply:cloud:poll-status');

        $this->assertSame(0, $exit);
        Queue::assertPushed(PollCloudStatusJob::class, 2);
    }

    public function test_include_active_flag_polls_active_sites_too(): void
    {
        Queue::fake();
        $this->makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING);
        $this->makeContainerSite(Site::STATUS_CONTAINER_ACTIVE);

        Artisan::call('dply:cloud:poll-status', ['--include-active' => true]);

        Queue::assertPushed(PollCloudStatusJob::class, 2);
    }

    public function test_skips_sites_without_backend_id(): void
    {
        Queue::fake();
        $this->makeContainerSite(Site::STATUS_CONTAINER_PROVISIONING, backendId: null);

        Artisan::call('dply:cloud:poll-status');

        Queue::assertNotPushed(PollCloudStatusJob::class);
    }

    public function test_no_op_when_no_sites_match(): void
    {
        Queue::fake();
        $exit = Artisan::call('dply:cloud:poll-status');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dispatched 0', Artisan::output());
    }

    private function makeContainerSite(string $status, ?string $backendId = 'app-12345'): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
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
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_backend_id' => $backendId,
            'container_region' => 'nyc',
            'status' => $status,
        ]);
    }
}
