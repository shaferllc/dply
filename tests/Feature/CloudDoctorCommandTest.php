<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CloudDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_healthy_edge_site_with_credential(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO production',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Healthy API',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'backend_id' => 'do-app-123',
                    'live_url' => 'https://healthy.ondigitalocean.app',
                    'last_phase' => 'ACTIVE',
                    'last_poll_at' => now()->toIso8601String(),
                    'provisioned_at' => now()->subHour()->toIso8601String(),
                ],
            ],
        ]);

        $exit = Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('Healthy API', $payload['site_name']);
        $this->assertSame(Site::STATUS_CONTAINER_ACTIVE, $payload['status']);
        $this->assertSame('digitalocean_app_platform', $payload['backend']['key']);
        $this->assertNotNull($payload['backend']['class']);
        $this->assertSame('do-app-123', $payload['backend']['backend_id']);
        $this->assertSame('DO production', $payload['credential']['name']);
        $this->assertSame('https://healthy.ondigitalocean.app', $payload['live']['url']);
        $this->assertSame('ACTIVE', $payload['live']['last_phase']);
        $this->assertNull($payload['probe']);
        $this->assertSame([], $payload['drift']);
        $this->assertNotEmpty($payload['timeline']);
    }

    public function test_fails_when_site_missing(): void
    {
        $exit = Artisan::call('dply:cloud:doctor', ['site' => 'nope']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', Artisan::output());
    }

    public function test_fails_when_site_is_not_edge(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'PHP Site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:cloud:doctor', ['site' => $site->name]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a cloud container site', Artisan::output());
    }

    public function test_drift_when_no_credential_connected(): void
    {
        config(['server_provision_fake.env_flag' => false]);
        [$user, $org] = $this->scaffold();
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Orphan',
            'status' => Site::STATUS_CONTAINER_PROVISIONING,
        ]);

        Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertNull($payload['credential']);
        $this->assertNotEmpty($payload['drift']);
        $this->assertTrue(collect($payload['drift'])->contains(fn ($d) => str_contains($d, 'No ProviderCredential connected')));
    }

    public function test_drift_when_active_without_live_url_or_backend_id(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Stuck',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => ['container' => []],
        ]);

        Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertNotEmpty($payload['drift']);
        $drift = collect($payload['drift']);
        $this->assertTrue($drift->contains(fn ($d) => str_contains($d, 'no live URL')));
        $this->assertTrue($drift->contains(fn ($d) => str_contains($d, 'no backend_id')));
    }

    public function test_drift_surfaces_recent_backend_error(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Burning',
            'status' => Site::STATUS_CONTAINER_FAILED,
            'meta' => [
                'container' => [
                    'last_error' => 'image pull failed: 401',
                    'last_error_at' => now()->subMinutes(5)->toIso8601String(),
                    'last_poll_error' => 'connection reset',
                ],
            ],
        ]);

        Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('image pull failed: 401', $payload['live']['last_error']);
        $drift = collect($payload['drift']);
        $this->assertTrue($drift->contains(fn ($d) => str_contains($d, 'image pull failed: 401')));
        $this->assertTrue($drift->contains(fn ($d) => str_contains($d, 'connection reset')));
    }

    public function test_doctor_reports_scale_and_github_webhook_for_source_site(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Scaled API',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'container_image' => null,
            'meta' => [
                'container' => [
                    'backend_id' => 'do-app-1',
                    'live_url' => 'https://x.ondigitalocean.app',
                    'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
                    'instance_count' => 4,
                    'size_tier' => 'large',
                ],
            ],
        ]);

        Artisan::call('dply:cloud:doctor', ['site' => $site->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(4, $payload['scale']['instances']);
        $this->assertSame('large', $payload['scale']['size_tier']);
        $this->assertNotNull($payload['github_webhook_url']);
        $this->assertStringContainsString('hooks/cloud/'.$site->id.'/github', $payload['github_webhook_url']);
    }

    public function test_source_mode_site_reports_repo_and_branch(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Built API',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'container_image' => null,
            'meta' => [
                'container' => [
                    'backend_id' => 'do-app-src',
                    'live_url' => 'https://built.ondigitalocean.app',
                    'source' => [
                        'repo' => 'acme/built',
                        'branch' => 'main',
                        'dockerfile_path' => 'Dockerfile',
                        'deploy_on_push' => true,
                    ],
                ],
            ],
        ]);

        Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('source', $payload['mode']);
        $this->assertSame('acme/built', $payload['source']['repo']);
        $this->assertSame('main', $payload['source']['branch']);
        $this->assertSame('Dockerfile', $payload['source']['dockerfile_path']);
        $this->assertTrue($payload['source']['deploy_on_push']);
    }

    public function test_timeline_is_newest_first_and_includes_domains(): void
    {
        [$user, $org] = $this->scaffold();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);
        $site = $this->makeContainerSite($user, $org, [
            'name' => 'Timely',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'backend_id' => 'do-1',
                    'live_url' => 'https://x.ondigitalocean.app',
                    'provisioned_at' => '2026-05-01T00:00:00Z',
                    'last_deploy_started_at' => '2026-05-02T00:00:00Z',
                    'domains' => [
                        'api.example.com' => [
                            'attached_at' => '2026-05-03T00:00:00Z',
                            'status' => 'verified',
                        ],
                    ],
                ],
            ],
        ]);

        Artisan::call('dply:cloud:doctor', [
            'site' => $site->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertCount(1, $payload['domains']);
        $this->assertSame('api.example.com', $payload['domains'][0]['hostname']);

        $kinds = array_column($payload['timeline'], 'kind');
        $this->assertSame('domain_attached', $kinds[0]);
        $this->assertContains('deploy', $kinds);
        $this->assertContains('provisioned', $kinds);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(User $user, Organization $org, array $overrides): Site
    {
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ], $overrides));
    }
}
