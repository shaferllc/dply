<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Edge\CreateEdgeSite;
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

class CreateEdgeSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_edge_server_and_site_and_dispatches_provision(): void
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

        $site = (new CreateEdgeSite)->handle($user, $org, [
            'name' => 'Acme API',
            'image' => 'ghcr.io/acme/api:v1',
            'port' => 8080,
            'region' => 'nyc',
            'backend' => 'auto',
            'env_file_content' => "APP_ENV=production\nLOG_LEVEL=info",
        ]);

        $this->assertSame(SiteType::Container, $site->type);
        $this->assertSame('digitalocean_app_platform', $site->container_backend);
        $this->assertSame('ghcr.io/acme/api:v1', $site->container_image);
        $this->assertSame(8080, $site->container_port);
        $this->assertSame('nyc', $site->container_region);
        $this->assertNotNull($site->server);
        $this->assertSame(Server::HOST_KIND_DPLY_EDGE, $site->server->hostKind());

        Queue::assertPushed(ProvisionEdgeSiteJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_explicit_backend_overrides_auto_pick(): void
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
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'aws_app_runner',
            'name' => 'AWS',
            'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
        ]);

        $site = (new CreateEdgeSite)->handle($user, $org, [
            'name' => 'Pinned to AWS',
            'image' => 'public.ecr.aws/acme/api:v1',
            'port' => 8080,
            'region' => 'us-east-1',
            'backend' => 'aws_app_runner',
        ]);

        $this->assertSame('aws_app_runner', $site->container_backend);
    }

    public function test_throws_when_no_container_credential_connected(): void
    {
        config(['server_provision_fake.env_flag' => false]);
        [$user, $org] = $this->scaffold();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No container backend connected');
        (new CreateEdgeSite)->handle($user, $org, [
            'name' => 'Lonely',
            'image' => 'nginx:1',
            'port' => 80,
            'region' => 'nyc',
            'backend' => 'auto',
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
