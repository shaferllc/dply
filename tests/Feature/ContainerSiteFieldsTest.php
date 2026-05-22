<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerSiteFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_columns_persist(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_registry' => 'ghcr.io',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_backend_id' => 'app-12345',
            'container_region' => 'nyc',
        ]);

        $fresh = $site->fresh();
        $this->assertSame('ghcr.io/acme/api:v1', $fresh->container_image);
        $this->assertSame(8080, $fresh->container_port);
        $this->assertSame('digitalocean_app_platform', $fresh->container_backend);
        $this->assertSame('app-12345', $fresh->container_backend_id);
        $this->assertSame('nyc', $fresh->container_region);
        $this->assertTrue($fresh->usesContainerRuntime());
    }

    public function test_container_runtime_helper_handles_legacy_backend_field(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => SiteType::Php,
            'container_backend' => 'aws_app_runner',
        ]);

        $this->assertTrue($site->fresh()->usesContainerRuntime());
    }

    public function test_php_site_does_not_use_container_runtime(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => SiteType::Php,
        ]);

        $this->assertFalse($site->fresh()->usesContainerRuntime());
    }

    public function test_server_host_kind_helpers_for_container_kinds(): void
    {
        $user = User::factory()->create();
        $appPlatform = Server::factory()->create([
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $appRunner = Server::factory()->create([
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_AWS_APP_RUNNER],
        ]);
        $edge = Server::factory()->create([
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $vm = Server::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($appPlatform->isDigitalOceanAppPlatformHost());
        $this->assertTrue($appPlatform->isContainerHost());
        $this->assertTrue($appRunner->isAwsAppRunnerHost());
        $this->assertTrue($appRunner->isContainerHost());
        $this->assertTrue($edge->isDplyEdgeHost());
        $this->assertTrue($edge->isContainerHost());
        $this->assertFalse($vm->isContainerHost());
    }

    public function test_container_live_url_reads_from_meta(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => SiteType::Container,
            'meta' => ['container' => ['live_url' => 'https://api-acme.ondigitalocean.app']],
        ]);

        $this->assertSame('https://api-acme.ondigitalocean.app', $site->fresh()->containerLiveUrl());
    }
}
