<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CloudListCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_all_cloud_sites(): void
    {
        $this->makeContainerSite('Site A', 'digitalocean_app_platform');
        $this->makeContainerSite('Site B', 'aws_app_runner');
        $this->makeVmSite('VM Site');

        $exit = Artisan::call('dply:cloud:list', ['--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(2, $payload['total']);
        $names = array_column($payload['sites'], 'site');
        $this->assertContains('Site A', $names);
        $this->assertContains('Site B', $names);
        $this->assertNotContains('VM Site', $names);
    }

    public function test_filter_by_backend(): void
    {
        $this->makeContainerSite('A', 'digitalocean_app_platform');
        $this->makeContainerSite('B', 'aws_app_runner');

        Artisan::call('dply:cloud:list', [
            '--json' => true,
            '--backend' => 'aws_app_runner',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('B', $payload['sites'][0]['site']);
    }

    public function test_filter_by_status(): void
    {
        $this->makeContainerSite('Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite('Broken', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

        Artisan::call('dply:cloud:list', [
            '--json' => true,
            '--status' => 'failed',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('Broken', $payload['sites'][0]['site']);
    }

    public function test_unknown_status_returns_failure(): void
    {
        $exit = Artisan::call('dply:cloud:list', ['--status' => 'bogus']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown --status', Artisan::output());
    }

    public function test_source_mode_site_shows_source_label_in_json(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Source Site',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']],
            ],
        ]);

        Artisan::call('dply:cloud:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('source', $payload['sites'][0]['mode']);
        $this->assertSame('acme/api@main', $payload['sites'][0]['source']);
        $this->assertNull($payload['sites'][0]['image']);
    }

    public function test_filter_mode_source(): void
    {
        $this->makeContainerSite('Source Site', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'container_image' => null,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);
        $this->makeContainerSite('Image Site', 'digitalocean_app_platform');

        Artisan::call('dply:cloud:list', ['--json' => true, '--mode' => 'source']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('Source Site', $payload['sites'][0]['site']);
    }

    public function test_filter_previews_only(): void
    {
        $parent = $this->makeContainerSite('Parent', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite('Preview', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => 'feature/x',
                ],
            ],
        ]);

        Artisan::call('dply:cloud:list', ['--json' => true, '--previews' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('Preview', $payload['sites'][0]['site']);
    }

    public function test_filter_no_previews_excludes_them(): void
    {
        $parent = $this->makeContainerSite('Parent', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite('Preview', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'meta' => [
                'container' => ['preview_parent_site_id' => $parent->id, 'preview_branch' => 'feature/x'],
            ],
        ]);

        Artisan::call('dply:cloud:list', ['--json' => true, '--no-previews' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('Parent', $payload['sites'][0]['site']);
    }

    public function test_json_includes_instances_and_size(): void
    {
        $this->makeContainerSite('Sized Site', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'meta' => ['container' => ['instance_count' => 3, 'size_tier' => 'large']],
        ]);

        Artisan::call('dply:cloud:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(3, $payload['sites'][0]['instances']);
        $this->assertSame('large', $payload['sites'][0]['size']);
    }

    public function test_unknown_mode_returns_failure(): void
    {
        $exit = Artisan::call('dply:cloud:list', ['--mode' => 'nope']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown --mode', Artisan::output());
    }

    public function test_empty_fleet_message(): void
    {
        $exit = Artisan::call('dply:cloud:list');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No cloud sites found', Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(string $name, string $backend, string $status = Site::STATUS_CONTAINER_ACTIVE, array $overrides = []): Site
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
            'name' => $name,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => $backend,
            'container_region' => 'nyc',
            'status' => $status,
        ], $overrides));
    }

    private function makeVmSite(string $name): Site
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => $name,
            'type' => SiteType::Php,
        ]);
    }
}
