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
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCloudWorkersJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Site, 1: ProviderCredential}
     */
    private function provisionedSite(?string $image = 'ghcr.io/acme/api:v1', ?array $source = null): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $credential = ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 'tok'],
        ]);
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        $meta = ['container' => []];
        if ($source !== null) {
            $meta['container']['source'] = $source;
        }
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => $image,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_backend_id' => 'do-app-1',
            'container_region' => 'nyc',
            'env_file_content' => "APP_ENV=production\nQUEUE_CONNECTION=redis",
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => $meta,
        ]);

        return [$site, $credential];
    }

    /** A minimal DO getApp response shape used by the spec-rebuild path. */
    private function fakeImageModeApp(): array
    {
        return ['app' => ['id' => 'do-app-1', 'spec' => [
            'name' => 'acme-api',
            'region' => 'nyc',
            'services' => [[
                'name' => 'web',
                'image' => ['registry_type' => 'DOCKER_HUB', 'repository' => 'acme/api', 'tag' => 'v1'],
                'http_port' => 8080,
                'instance_count' => 1,
                'instance_size_slug' => 'basic-xxs',
            ]],
        ]]];
    }

    public function test_sync_pushes_worker_components_into_the_spec_and_redeploys(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments' => Http::response(['deployment' => ['id' => 'dep-1']], 200),
            'api.digitalocean.com/v2/apps/do-app-1' => Http::response($this->fakeImageModeApp(), 200),
        ]);

        [$site, $credential] = $this->provisionedSite();
        CloudWorker::factory()->create([
            'site_id' => $site->id,
            'type' => CloudWorker::TYPE_WORKER,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'size' => 'medium',
            'instance_count' => 2,
        ]);
        CloudWorker::factory()->scheduler()->create(['site_id' => $site->id]);

        (new DigitalOceanAppPlatformBackend)->syncWorkers($site->fresh(), $credential);

        Http::assertSent(function ($req) {
            if ($req->method() !== 'PUT') {
                return false;
            }
            $workers = $req->data()['spec']['workers'] ?? [];
            if (count($workers) !== 2) {
                return false;
            }
            $byCommand = [];
            foreach ($workers as $w) {
                $byCommand[$w['run_command']] = $w;
            }

            $queue = $byCommand['php artisan queue:work'] ?? null;
            $sched = $byCommand['php artisan schedule:work'] ?? null;

            return $queue !== null
                && $queue['instance_count'] === 2
                && $queue['instance_size_slug'] === 'basic-xs'
                && ($queue['image']['repository'] ?? null) === 'acme/api'
                && $sched !== null
                && $sched['instance_count'] === 1;
        });
    }

    public function test_sync_carries_env_vars_onto_worker_components(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments' => Http::response(['deployment' => ['id' => 'dep-1']], 200),
            'api.digitalocean.com/v2/apps/do-app-1' => Http::response($this->fakeImageModeApp(), 200),
        ]);

        [$site, $credential] = $this->provisionedSite();
        CloudWorker::factory()->create(['site_id' => $site->id]);

        (new DigitalOceanAppPlatformBackend)->syncWorkers($site->fresh(), $credential);

        Http::assertSent(function ($req) {
            if ($req->method() !== 'PUT') {
                return false;
            }
            $worker = $req->data()['spec']['workers'][0] ?? [];
            $keys = array_column($worker['envs'] ?? [], 'key');

            return in_array('APP_ENV', $keys, true) && in_array('QUEUE_CONNECTION', $keys, true);
        });
    }

    public function test_sync_omits_workers_key_when_no_workers_remain(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments' => Http::response(['deployment' => ['id' => 'dep-1']], 200),
            'api.digitalocean.com/v2/apps/do-app-1' => Http::response($this->fakeImageModeApp(), 200),
        ]);

        [$site, $credential] = $this->provisionedSite();
        // No CloudWorker rows.

        (new DigitalOceanAppPlatformBackend)->syncWorkers($site->fresh(), $credential);

        Http::assertSent(function ($req) {
            if ($req->method() !== 'PUT') {
                return false;
            }

            return ! array_key_exists('workers', $req->data()['spec'] ?? []);
        });
    }

    public function test_job_marks_workers_active_on_success(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1/deployments' => Http::response(['deployment' => ['id' => 'dep-1']], 200),
            'api.digitalocean.com/v2/apps/do-app-1' => Http::response($this->fakeImageModeApp(), 200),
        ]);

        [$site] = $this->provisionedSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        (new SyncCloudWorkersJob($site->id))->handle();

        $this->assertSame(CloudWorker::STATUS_ACTIVE, $worker->fresh()->status);
    }

    public function test_job_marks_workers_failed_on_backend_error(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps/do-app-1' => Http::response(['message' => 'boom'], 500),
        ]);

        [$site] = $this->provisionedSite();
        $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

        try {
            (new SyncCloudWorkersJob($site->id))->handle();
        } catch (\Throwable) {
            // job rethrows so the queue retries
        }

        $fresh = $worker->fresh();
        $this->assertSame(CloudWorker::STATUS_FAILED, $fresh->status);
        $this->assertNotEmpty($fresh->meta['error'] ?? null);
    }

    public function test_job_no_op_on_missing_site(): void
    {
        (new SyncCloudWorkersJob('01nope0000000000000000nope'))->handle();
        $this->assertTrue(true);
    }
}
