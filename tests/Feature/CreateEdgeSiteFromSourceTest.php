<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Edge\CreateEdgeSiteFromSource;
use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateEdgeSiteFromSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_site_with_source_meta_and_dispatches_provision(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $site = (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'API service',
            'repo' => 'acme/api',
            'branch' => 'main',
            'dockerfile_path' => 'Dockerfile',
            'deploy_on_push' => true,
            'port' => 8080,
            'region' => 'nyc',
            'backend' => 'digitalocean_app_platform',
        ]);

        $this->assertSame(SiteType::Container, $site->type);
        $this->assertNull($site->container_image);
        $this->assertSame('digitalocean_app_platform', $site->container_backend);

        $source = $site->meta['container']['source'] ?? [];
        $this->assertSame('acme/api', $source['repo']);
        $this->assertSame('main', $source['branch']);
        $this->assertSame('Dockerfile', $source['dockerfile_path']);
        $this->assertTrue($source['deploy_on_push']);

        $server = Server::query()->find($site->server_id);
        $this->assertNotNull($server);
        $this->assertSame(Server::HOST_KIND_DPLY_EDGE, $server->meta['host_kind'] ?? null);

        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_normalizes_full_github_url_to_owner_name(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $site = (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'svc',
            'repo' => 'https://github.com/acme/api.git',
            'branch' => 'main',
            'backend' => 'digitalocean_app_platform',
            'region' => 'nyc',
        ]);

        $this->assertSame('acme/api', $site->meta['container']['source']['repo']);
    }

    public function test_omits_dockerfile_path_from_meta_when_blank(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $site = (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'svc',
            'repo' => 'acme/api',
            'branch' => 'main',
            'backend' => 'digitalocean_app_platform',
            'region' => 'nyc',
        ]);

        $this->assertArrayNotHasKey('dockerfile_path', $site->meta['container']['source']);
    }

    public function test_rejects_blank_repo(): void
    {
        [$user, $org] = $this->scaffold();

        $this->expectException(\InvalidArgumentException::class);
        (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'svc',
            'repo' => '',
            'branch' => 'main',
            'backend' => 'digitalocean_app_platform',
            'region' => 'nyc',
        ]);
    }

    public function test_auto_backend_picks_a_connected_one(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws_app_runner',
            'name' => 'AWS',
            'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's'],
        ]);

        $site = (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'svc',
            'repo' => 'acme/api',
            'branch' => 'main',
            'backend' => 'auto',
            'region' => 'us-east-1',
        ]);

        $this->assertSame('aws_app_runner', $site->container_backend);
    }

    public function test_auto_backend_throws_when_none_connected(): void
    {
        [$user, $org] = $this->scaffold();

        $this->expectException(\RuntimeException::class);
        (new CreateEdgeSiteFromSource)->handle($user, $org, [
            'name' => 'svc',
            'repo' => 'acme/api',
            'branch' => 'main',
            'backend' => 'auto',
            'region' => 'nyc',
        ]);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }
}
