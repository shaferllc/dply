<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Jobs\RedeployEdgeSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EdgeDeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_edge_site_for_new_name(): void
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

        $exit = Artisan::call('dply:edge:deploy', [
            'name' => 'New Service',
            '--image' => 'ghcr.io/acme/api:v1',
            '--region' => 'nyc',
            '--backend' => 'digitalocean_app_platform',
            '--user' => $user->email,
            '--org' => $org->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('sites', [
            'name' => 'New Service',
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_backend' => 'digitalocean_app_platform',
        ]);
        Queue::assertPushed(ProvisionEdgeSiteJob::class);
    }

    public function test_redeploys_existing_site_with_image_bump(): void
    {
        Queue::fake();
        [$user, $org] = $this->scaffold();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Existing Service',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
        ]);

        $exit = Artisan::call('dply:edge:deploy', [
            'name' => 'Existing Service',
            '--image' => 'ghcr.io/acme/api:v2',
            '--user' => $user->email,
            '--org' => $org->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Image-bump redeploy queued', Artisan::output());
        Queue::assertPushed(RedeployEdgeSiteJob::class, function (RedeployEdgeSiteJob $j): bool {
            return $j->newImage === 'ghcr.io/acme/api:v2';
        });
    }

    public function test_fails_when_image_missing(): void
    {
        $exit = Artisan::call('dply:edge:deploy', ['name' => 'Foo']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--image is required', Artisan::output());
    }

    public function test_fails_when_no_org_resolvable(): void
    {
        $exit = Artisan::call('dply:edge:deploy', [
            'name' => 'Foo',
            '--image' => 'nginx:1',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not resolve organization', Artisan::output());
    }

    public function test_fails_when_no_credential_connected(): void
    {
        config(['server_provision_fake.env_flag' => false]);
        [$user, $org] = $this->scaffold();

        $exit = Artisan::call('dply:edge:deploy', [
            'name' => 'No Backend',
            '--image' => 'nginx:1',
            '--region' => 'nyc',
            '--user' => $user->email,
            '--org' => $org->id,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No container backend connected', Artisan::output());
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
