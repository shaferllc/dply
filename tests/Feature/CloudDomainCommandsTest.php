<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\AttachCloudDomainJob;
use App\Jobs\DetachCloudDomainJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloudDomainCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_queues_job(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:domain:attach', [
            'site' => $site->name,
            'hostname' => 'api.example.com',
        ]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(AttachCloudDomainJob::class, fn ($j) => $j->siteId === $site->id && $j->hostname === 'api.example.com');
    }

    public function test_attach_normalizes_uppercase_and_strips_scheme(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        Artisan::call('dply:cloud:domain:attach', [
            'site' => $site->name,
            'hostname' => 'https://API.Example.COM/',
        ]);

        Queue::assertPushed(AttachCloudDomainJob::class, fn ($j) => $j->hostname === 'api.example.com');
    }

    public function test_attach_rejects_invalid_hostname(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:domain:attach', [
            'site' => $site->name,
            'hostname' => 'not a hostname',
        ]);

        $this->assertSame(1, $exit);
        Queue::assertNotPushed(AttachCloudDomainJob::class);
    }

    public function test_detach_queues_job(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:domain:detach', [
            'site' => $site->name,
            'hostname' => 'api.example.com',
        ]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(DetachCloudDomainJob::class, fn ($j) => $j->siteId === $site->id && $j->hostname === 'api.example.com');
    }

    public function test_list_emits_json_with_attached_domains(): void
    {
        $site = $this->makeContainerSite([
            'meta' => [
                'container' => [
                    'domains' => [
                        'api.example.com' => ['attached_at' => '2026-05-03T00:00:00Z', 'status' => 'verified'],
                        'preview.example.com' => ['attached_at' => '2026-05-04T00:00:00Z', 'status' => 'pending'],
                    ],
                ],
            ],
        ]);

        Artisan::call('dply:cloud:domain:list', ['site' => $site->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $payload['total']);
        $hostnames = array_column($payload['domains'], 'hostname');
        $this->assertContains('api.example.com', $hostnames);
        $this->assertContains('preview.example.com', $hostnames);
    }

    public function test_list_empty_state(): void
    {
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:domain:list', ['site' => $site->name]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No custom domains attached', Artisan::output());
    }

    public function test_attach_rejects_non_cloud_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:cloud:domain:attach', [
            'site' => $vmSite->name,
            'hostname' => 'api.example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a cloud container site', Artisan::output());
    }

    public function test_attach_missing_site(): void
    {
        $exit = Artisan::call('dply:cloud:domain:attach', [
            'site' => 'nope',
            'hostname' => 'api.example.com',
        ]);

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
