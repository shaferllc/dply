<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cloud\CreateCloudWorker;
use App\Enums\SiteType;
use App\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\Cloud\FakeCloudBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CreateCloudWorkerTest extends TestCase
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

    // --- supportsWorkers per backend -----------------------------------

    public function test_do_backend_supports_workers(): void
    {
        $this->assertTrue((new DigitalOceanAppPlatformBackend)->supportsWorkers());
    }

    public function test_fake_backend_supports_workers(): void
    {
        $this->assertTrue((new FakeCloudBackend)->supportsWorkers());
    }

    public function test_app_runner_backend_does_not_support_workers(): void
    {
        $this->assertFalse((new AwsAppRunnerBackend)->supportsWorkers());
    }

    public function test_app_runner_sync_workers_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App Runner does not support background workers');

        (new AwsAppRunnerBackend)->syncWorkers(new Site, new ProviderCredential);
    }

    // --- CreateCloudWorker ---------------------------------------------

    public function test_creates_worker_on_do_site_and_dispatches_sync(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $worker = (new CreateCloudWorker)->handle($site, [
            'type' => 'worker',
            'command' => 'php artisan queue:work redis',
            'size' => 'medium',
            'instance_count' => 3,
        ]);

        $this->assertSame(CloudWorker::TYPE_WORKER, $worker->type);
        $this->assertSame('php artisan queue:work redis', $worker->command);
        $this->assertSame('medium', $worker->size);
        $this->assertSame(3, $worker->instance_count);
        $this->assertSame(CloudWorker::STATUS_PROVISIONING, $worker->status);

        Bus::assertDispatched(SyncCloudWorkersJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_worker_command_defaults_when_blank(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $worker = (new CreateCloudWorker)->handle($site, ['type' => 'worker', 'command' => '']);

        $this->assertSame('php artisan queue:work', $worker->command);
    }

    public function test_creates_scheduler_pinned_to_one_instance(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $worker = (new CreateCloudWorker)->handle($site, [
            'type' => 'scheduler',
            'instance_count' => 9,
        ]);

        $this->assertSame(CloudWorker::TYPE_SCHEDULER, $worker->type);
        $this->assertSame('php artisan schedule:work', $worker->command);
        $this->assertSame(1, $worker->instance_count);
    }

    public function test_rejects_second_scheduler(): void
    {
        Bus::fake();
        $site = $this->containerSite();
        (new CreateCloudWorker)->handle($site, ['type' => 'scheduler']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a scheduler');
        (new CreateCloudWorker)->handle($site, ['type' => 'scheduler']);
    }

    public function test_rejects_app_runner_site(): void
    {
        Bus::fake();
        $site = $this->containerSite('aws_app_runner');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('App Runner does not support background workers');
        (new CreateCloudWorker)->handle($site, ['type' => 'worker']);
    }

    public function test_rejects_non_container_site(): void
    {
        $server = Server::factory()->ready()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Php]);

        $this->expectException(\InvalidArgumentException::class);
        (new CreateCloudWorker)->handle($site, ['type' => 'worker']);
    }

    public function test_rejects_unknown_type(): void
    {
        $site = $this->containerSite();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown worker type');
        (new CreateCloudWorker)->handle($site, ['type' => 'daemon']);
    }

    public function test_unknown_size_falls_back_to_small(): void
    {
        Bus::fake();
        $site = $this->containerSite();

        $worker = (new CreateCloudWorker)->handle($site, ['type' => 'worker', 'size' => 'enormous']);

        $this->assertSame('small', $worker->size);
    }
}
