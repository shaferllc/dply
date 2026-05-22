<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CloudWorkerCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function containerSite(string $backend = 'digitalocean_app_platform'): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $backend,
            'name' => 'cred',
            'credentials' => ['api_token' => 'tok', 'github_connection_arn' => 'arn:x'],
        ]);
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => $backend,
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);
    }

    // --- worker:add -----------------------------------------------------

    public function test_add_command_creates_worker_and_queues_sync(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $exit = Artisan::call('dply:cloud:worker:add', [
            '--site' => $site->id,
            '--command' => 'php artisan queue:work',
            '--size' => 'medium',
            '--count' => 2,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('cloud_workers', [
            'site_id' => $site->id,
            'type' => 'worker',
            'size' => 'medium',
            'instance_count' => 2,
        ]);
        Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_add_command_rejects_app_runner_site(): void
    {
        Bus::fake();
        $site = $this->containerSite('aws_app_runner');

        $exit = Artisan::call('dply:cloud:worker:add', ['--site' => $site->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('App Runner does not support background workers', Artisan::output());
        Bus::assertNotDispatched(SyncCloudWorkersJob::class);
    }

    public function test_add_command_fails_on_unknown_site(): void
    {
        $exit = Artisan::call('dply:cloud:worker:add', ['--site' => 'nope']);
        $this->assertSame(1, $exit);
    }

    public function test_add_scheduler_type(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $exit = Artisan::call('dply:cloud:worker:add', ['--site' => $site->id, '--type' => 'scheduler']);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
    }

    // --- worker:list ----------------------------------------------------

    public function test_list_command_json(): void
    {
        $site = $this->containerSite();
        CloudWorker::factory()->create(['site_id' => $site->id]);
        CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        $exit = Artisan::call('dply:cloud:worker:list', ['--site' => $site->id, '--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(2, $payload['total']);
    }

    public function test_list_command_empty_message(): void
    {
        $site = $this->containerSite();

        $exit = Artisan::call('dply:cloud:worker:list', ['--site' => $site->id]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No workers configured', Artisan::output());
    }

    public function test_list_command_requires_site(): void
    {
        $exit = Artisan::call('dply:cloud:worker:list');
        $this->assertSame(1, $exit);
    }

    // --- worker:scale ---------------------------------------------------

    public function test_scale_command_changes_count_and_queues_sync(): void
    {
        Bus::fake();
        $site = $this->containerSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id, 'instance_count' => 1]);

        $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => $worker->id, '--count' => 4, '--size' => 'large']);

        $this->assertSame(0, $exit);
        $fresh = $worker->fresh();
        $this->assertSame(4, $fresh->instance_count);
        $this->assertSame('large', $fresh->size);
        Bus::assertDispatched(SyncCloudWorkersJob::class);
    }

    public function test_scale_command_requires_a_change(): void
    {
        $site = $this->containerSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => $worker->id]);
        $this->assertSame(1, $exit);
    }

    public function test_scale_command_fails_on_unknown_worker(): void
    {
        $exit = Artisan::call('dply:cloud:worker:scale', ['worker' => 'nope', '--count' => 2]);
        $this->assertSame(1, $exit);
    }

    // --- worker:remove --------------------------------------------------

    public function test_remove_command_deletes_worker_and_queues_sync(): void
    {
        Bus::fake();
        $site = $this->containerSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        $exit = Artisan::call('dply:cloud:worker:remove', ['worker' => $worker->id]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('cloud_workers', ['id' => $worker->id]);
        Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_remove_command_fails_on_unknown_worker(): void
    {
        $exit = Artisan::call('dply:cloud:worker:remove', ['worker' => 'nope']);
        $this->assertSame(1, $exit);
    }

    // --- scheduler:enable / disable ------------------------------------

    public function test_scheduler_enable_command_creates_scheduler(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('cloud_workers', ['site_id' => $site->id, 'type' => 'scheduler']);
        Bus::assertDispatched(SyncCloudWorkersJob::class);
    }

    public function test_scheduler_enable_rejects_app_runner_site(): void
    {
        Bus::fake();
        $site = $this->containerSite('aws_app_runner');

        $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);
        $this->assertSame(1, $exit);
    }

    public function test_scheduler_enable_rejects_second_scheduler(): void
    {
        Bus::fake();
        $site = $this->containerSite();
        CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        $exit = Artisan::call('dply:cloud:scheduler:enable', ['--site' => $site->id]);
        $this->assertSame(1, $exit);
    }

    public function test_scheduler_disable_command_removes_scheduler(): void
    {
        Bus::fake();
        $site = $this->containerSite();
        $scheduler = CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        $exit = Artisan::call('dply:cloud:scheduler:disable', ['--site' => $site->id]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('cloud_workers', ['id' => $scheduler->id]);
        Bus::assertDispatched(SyncCloudWorkersJob::class);
    }

    public function test_scheduler_disable_is_graceful_when_no_scheduler(): void
    {
        $site = $this->containerSite();

        $exit = Artisan::call('dply:cloud:scheduler:disable', ['--site' => $site->id]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no scheduler', Artisan::output());
    }
}
