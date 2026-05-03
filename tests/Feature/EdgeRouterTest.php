<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\AwsAppRunnerBackend;
use App\Services\Edge\DigitalOceanAppPlatformBackend;
use App\Services\Edge\EdgeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_backend_for_returns_correct_implementation(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $doSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'container_backend' => 'digitalocean_app_platform',
        ]);
        $arSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'container_backend' => 'aws_app_runner',
        ]);

        $this->assertInstanceOf(DigitalOceanAppPlatformBackend::class, EdgeRouter::backendFor($doSite));
        $this->assertInstanceOf(AwsAppRunnerBackend::class, EdgeRouter::backendFor($arSite));
    }

    public function test_backend_for_returns_null_for_unknown_backend(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'container_backend' => null,
        ]);

        $this->assertNull(EdgeRouter::backendFor($site));
    }

    public function test_credential_for_prefers_meta_credential_id_when_set(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $cred1 = $this->makeCredential($user, $org, 'digitalocean_app_platform', 'First');
        $cred2 = $this->makeCredential($user, $org, 'digitalocean_app_platform', 'Second');
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'container_backend' => 'digitalocean_app_platform',
            'meta' => ['container' => ['credential_id' => $cred2->id]],
        ]);

        $resolved = EdgeRouter::credentialFor($site);
        $this->assertSame($cred2->id, $resolved?->id);
    }

    public function test_credential_for_falls_back_to_first_matching_provider(): void
    {
        [$user, $org, $server] = $this->scaffold();
        $cred = $this->makeCredential($user, $org, 'aws_app_runner', 'Only one');
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'container_backend' => 'aws_app_runner',
        ]);

        $this->assertSame($cred->id, EdgeRouter::credentialFor($site)?->id);
    }

    public function test_pick_auto_backend_prefers_do_over_aws(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $this->makeCredential($user, $org, 'digitalocean_app_platform', 'DO');
        $this->makeCredential($user, $org, 'aws_app_runner', 'AWS');

        $this->assertSame('digitalocean_app_platform', EdgeRouter::pickAutoBackend($org->id));
    }

    public function test_pick_auto_backend_returns_aws_when_only_aws_connected(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $this->makeCredential($user, $org, 'aws_app_runner', 'AWS');

        $this->assertSame('aws_app_runner', EdgeRouter::pickAutoBackend($org->id));
    }

    public function test_pick_auto_backend_returns_null_when_no_credential(): void
    {
        $org = Organization::factory()->create();
        $this->assertNull(EdgeRouter::pickAutoBackend($org->id));
    }

    /**
     * @return array{0: User, 1: Organization, 2: Server}
     */
    private function scaffold(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return [$user, $org, $server];
    }

    private function makeCredential(User $user, Organization $org, string $provider, string $name): ProviderCredential
    {
        return ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $provider,
            'name' => $name,
            'credentials' => $provider === 'aws_app_runner'
                ? ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1']
                : ['api_token' => 't'],
        ]);
    }
}
